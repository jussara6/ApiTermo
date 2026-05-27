<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

class TermooController extends Controller
{
    private const TAMANHO_PALAVRA = 5;
    private const TENTATIVAS_MAXIMAS = 6;
    private const TTL_JOGO = 3600; // 1 hora em segundos

    /**
     * POST /api/iniciar-jogo
     * Inicia uma nova partida e retorna o ID do jogo
     */
    public function iniciarJogo(): JsonResponse
    {
        $palavras = $this->getDicionario();
        $palavraEscolhida = $palavras[array_rand($palavras)];

        $idJogo = (string) Str::uuid();

        $estadoJogo = [
            'palavra'            => $palavraEscolhida,
            'tentativasRestantes' => self::TENTATIVAS_MAXIMAS,
            'venceu'             => false,
            'encerrado'          => false,
        ];

        Cache::put("jogo:{$idJogo}", $estadoJogo, self::TTL_JOGO);

        return response()->json([
            'idJogo'           => $idJogo,
            'tamanhoPalavra'   => self::TAMANHO_PALAVRA,
            'tentativasMaximas' => self::TENTATIVAS_MAXIMAS,
        ], 200);
    }

    /**
     * POST /api/jogos/{idJogo}/tentativas  (padrão REST — front-end do professor)
     * idJogo vem na URL; palavra vem no body JSON.
     */
    public function validarTentativaPorRota(Request $request, string $idJogo): JsonResponse
    {
        $request->merge(['idJogo' => $idJogo]);
        return $this->validarTentativa($request);
    }

    /**
     * POST /api/validar-tentativa  (rota legada — mantida para compatibilidade)
     * Valida uma tentativa do jogador e retorna o resultado letra a letra
     */
    public function validarTentativa(Request $request): JsonResponse
    {
        $idJogo  = $request->input('idJogo');
        $palavra = $request->input('palavra');

        // Validação básica dos campos obrigatórios
        if (empty($idJogo) || empty($palavra)) {
            return response()->json([
                'erro' => 'Os campos idJogo e palavra são obrigatórios.',
            ], 400);
        }

        // Buscar estado do jogo no cache
        $estadoJogo = Cache::get("jogo:{$idJogo}");

        if (!$estadoJogo) {
            return response()->json([
                'erro' => 'Jogo não encontrado ou expirado.',
            ], 404);
        }

        // Normaliza a tentativa: minúsculas e remove acentos para comparação
        $palavraNormalizada = $this->normalizar($palavra);

        // Verifica se o tamanho está correto
        if (mb_strlen($palavraNormalizada) !== self::TAMANHO_PALAVRA) {
            return response()->json([
                'resultado'          => [],
                'venceu'             => false,
                'tentativasRestantes' => $estadoJogo['tentativasRestantes'],
                'palavraValida'      => false,
            ], 200);
        }

        // Verifica se a palavra existe no dicionário
        $dicionario           = $this->getDicionario();
        $dicionarioNormalizado = array_map([$this, 'normalizar'], $dicionario);

        if (!in_array($palavraNormalizada, $dicionarioNormalizado)) {
            return response()->json([
                'resultado'          => [],
                'venceu'             => false,
                'tentativasRestantes' => $estadoJogo['tentativasRestantes'],
                'palavraValida'      => false,
            ], 200);
        }

        // Verifica se o jogo já foi encerrado
        if ($estadoJogo['encerrado']) {
            return response()->json([
                'resultado'          => [],
                'venceu'             => $estadoJogo['venceu'],
                'tentativasRestantes' => 0,
                'palavraValida'      => true,
            ], 200);
        }

        // Calcula o resultado da tentativa
        $palavraSecreta         = $this->normalizar($estadoJogo['palavra']);
        $resultado              = $this->calcularResultado($palavraNormalizada, $palavraSecreta);
        $venceu                 = ($palavraNormalizada === $palavraSecreta);
        $tentativasRestantes    = $estadoJogo['tentativasRestantes'] - 1;
        $encerrado              = $venceu || $tentativasRestantes <= 0;

        // Atualiza o estado do jogo
        $estadoJogo['tentativasRestantes'] = $tentativasRestantes;
        $estadoJogo['venceu']              = $venceu;
        $estadoJogo['encerrado']           = $encerrado;

        Cache::put("jogo:{$idJogo}", $estadoJogo, self::TTL_JOGO);

        return response()->json([
            'resultado'          => $resultado,
            'venceu'             => $venceu,
            'tentativasRestantes' => $tentativasRestantes,
            'palavraValida'      => true,
        ], 200);
    }

    /**
     * Calcula o resultado letra a letra da tentativa em relação à palavra secreta.
     *
     * Regras (compatíveis com o Wordle / Termo):
     *  - "correta"  → letra na posição certa
     *  - "presente" → letra existe na palavra, mas está em posição errada
     *  - "ausente"  → letra não existe na palavra
     *
     * O algoritmo leva em conta letras duplicadas de forma correta:
     * marca primeiro as "corretas" e só então distribui os "presentes",
     * respeitando a quantidade de ocorrências de cada letra.
     */
    private function calcularResultado(string $tentativa, string $secreta): array
    {
        $tentativaLetras = mb_str_split($tentativa);
        $secretaLetras   = mb_str_split($secreta);
        $resultado       = array_fill(0, self::TAMANHO_PALAVRA, null);

        // Contagem de letras disponíveis na palavra secreta (para controle de duplicatas)
        $contagem = array_count_values($secretaLetras);

        // 1ª passagem: marcar letras corretas (posição e letra certas)
        for ($i = 0; $i < self::TAMANHO_PALAVRA; $i++) {
            if ($tentativaLetras[$i] === $secretaLetras[$i]) {
                $resultado[$i] = [
                    'letra'  => $tentativaLetras[$i],
                    'status' => 'correta',
                ];
                $contagem[$tentativaLetras[$i]]--;
            }
        }

        // 2ª passagem: marcar presentes e ausentes
        for ($i = 0; $i < self::TAMANHO_PALAVRA; $i++) {
            if ($resultado[$i] !== null) {
                continue; // Já processado como "correta"
            }

            $letra = $tentativaLetras[$i];

            if (isset($contagem[$letra]) && $contagem[$letra] > 0) {
                $resultado[$i] = [
                    'letra'  => $letra,
                    'status' => 'presente',
                ];
                $contagem[$letra]--;
            } else {
                $resultado[$i] = [
                    'letra'  => $letra,
                    'status' => 'ausente',
                ];
            }
        }

        return $resultado;
    }

    /**
     * Normaliza uma string: converte para minúsculas e remove acentos.
     * Isso permite comparar "Ação" com "acao" como iguais.
     */
    private function normalizar(string $str): string
    {
        $str = mb_strtolower(trim($str));

        // Remove acentos via transliteração
        $acentos = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];

        return strtr($str, $acentos);
    }

    /**
     * Retorna o dicionário completo de palavras de 5 letras.
     */
    private function getDicionario(): array
    {
        return [
            'sagaz', 'amago', 'termo', 'negro', 'exito', 'mexer', 'nobre', 'senso', 'etica', 'afeto',
            'algoz', 'fazer', 'plena', 'tenue', 'assim', 'sobre', 'mutua', 'aquem', 'poder', 'secao',
            'vigor', 'porem', 'sutil', 'fosse', 'cerne', 'ideia', 'sanar', 'audaz', 'moral', 'inato',
            'quica', 'muito', 'justo', 'desde', 'honra', 'sonho', 'torpe', 'amigo', 'razao', 'egide',
            'icone', 'etnia', 'futil', 'anexo', 'dengo', 'tange', 'haver', 'lapso', 'entao', 'tempo',
            'expor', 'seara', 'bocal', 'saber', 'habil', 'graca', 'mutuo', 'xibiu', 'casal', 'obice',
            'avido', 'dizer', 'ardil', 'estar', 'pesar', 'dever', 'causa', 'tenaz', 'ainda', 'sendo',
            'temor', 'crivo', 'brado', 'paria', 'coser', 'apice', 'genro', 'comum', 'posse', 'prole',
            'assaz', 'corja', 'animo', 'fugaz', 'ceder', 'pauta', 'censo', 'detem', 'culto', 'ansia',
            'atroz', 'digno', 'mundo', 'forte', 'mesmo', 'vulgo', 'vicio', 'saude', 'gleba', 'criar',
            'todos', 'cozer', 'reves', 'jeito', 'pudor', 'dogma', 'valha', 'denso', 'nenem', 'louco',
            'limbo', 'ordem', 'atras', 'regra', 'pedir', 'homem', 'feliz', 'ajuda', 'clava', 'usura',
            'merce', 'impor', 'banal', 'coisa', 'falso', 'juizo', 'round', 'legal', 'forma', 'falar',
            'sabio', 'certo', 'prosa', 'servo', 'tenro', 'presa', 'pifio', 'posso', 'desse', 'heroi',
            'cunho', 'devir', 'facil', 'viril', 'ontem', 'vendo', 'valor', 'visar', 'linda', 'manso',
            'serio', 'ebrio', 'guisa', 'magoa', 'acaso', 'meiga', 'impio', 'puder', 'fluir', 'afago',
            'lugar', 'reaca', 'abrir', 'temer', 'plato', 'garbo', 'praxe', 'uniao', 'gerar', 'burro',
            'obter', 'matiz', 'obvio', 'cisma', 'bruma', 'venia', 'afins', 'exodo', 'crise', 'pleno',
            'alibi', 'ritmo', 'tedio', 'fluxo', 'morte', 'levar', 'senil', 'olhar', 'casta', 'havia',
            'tomar', 'enfim', 'visao', 'ouvir', 'genio', 'parvo', 'prumo', 'cabal', 'brega', 'parco',
            'reles', 'falta', 'calma', 'vital', 'outro', 'tecer', 'bravo', 'favor', 'pulha', 'terra',
            'reter', 'vivaz', 'viver', 'ameno', 'sabia', 'forca', 'unico', 'tendo', 'laico', 'passo',
            'nicho', 'valia', 'achar', 'grato', 'nossa', 'carma', 'rever', 'papel', 'nocao', 'ranco',
            'pobre', 'possa', 'rogar', 'dubio', 'noite', 'fardo', 'ativo', 'facam', 'prime', 'farsa',
            'coeso', 'epico', 'fator', 'anelo', 'claro', 'leigo', 'lider', 'sesta', 'selar', 'obito',
            'vazio', 'ciume', 'cisao', 'cesta', 'sonso', 'ficar', 'citar', 'deter', 'sinto', 'atuar',
            'velho', 'gente', 'haste', 'fonte', 'adiar', 'ponto', 'tende', 'humor', 'revel', 'ideal',
            'sulco', 'senao', 'arduo', 'labor', 'remir', 'terno', 'igual', 'marco', 'hiato', 'feixe',
            'exato', 'capaz', 'amplo', 'debil', 'tanto', 'lavra', 'relva', 'vemos', 'ciclo', 'cauda',
            'tenra', 'inata', 'jovem', 'raiva', 'chuva', 'varao', 'otica', 'gesto', 'cacar', 'ambos',
            'pouco', 'toada', 'velar', 'sonsa', 'apoio', 'cocar', 'serie', 'vacuo', 'imune', 'xeque',
            'algum', 'farao', 'feito', 'horda', 'vimos', 'carro', 'fusao', 'entre', 'advem', 'sorte',
            'leito', 'coesa', 'probo', 'minha', 'trama', 'cruel', 'sente', 'doido', 'anuir', 'lazer',
            'frase', 'brisa', 'impar', 'verso', 'chata', 'blase', 'rigor', 'massa', 'pegar', 'torco',
            'maior', 'prece', 'botar', 'aurea', 'seita', 'dorso', 'saiba', 'agora', 'signo', 'furor',
            'fauna', 'mocao', 'livro', 'plano', 'liame', 'vetor', 'comer', 'ocaso', 'senda', 'covil',
            'preso', 'credo', 'casto', 'flora', 'morar', 'praia', 'pecha', 'nunca', 'faina', 'alias',
            'docil', 'adeus', 'houve', 'peste', 'ardor', 'mudar', 'arido', 'setor', 'parte', 'manha',
            'ambas', 'peixe', 'risco', 'antro', 'rezar', 'visse', 'pajem', 'estao', 'grupo', 'junto',
            'avaro', 'vulto', 'virus', 'salvo', 'meses', 'campo', 'otimo', 'indio', 'saida', 'beata',
            'breve', 'vasto', 'antes', 'aceso', 'morro', 'conta', 'sinal', 'verbo', 'andar', 'anais',
            'lenda', 'reger', 'oxala', 'aureo', 'banzo', 'prado', 'fugir', 'acima', 'opcao', 'serao',
            'festa', 'vilao', 'chulo', 'rapaz', 'nacao', 'texto', 'segue', 'leite', 'motim', 'birra',
            'fruir', 'tirar', 'treta', 'parar', 'brava', 'bonus', 'fitar', 'atrio', 'idolo', 'puxar',
            'jazia', 'filho', 'turba', 'atomo', 'alude', 'tosco', 'gerir', 'reino', 'tenso', 'prova',
            'prazo', 'traga', 'norma', 'manha', 'exame', 'epoca', 'voraz', 'corpo', 'acesa', 'cheio',
            'sarca', 'ligar', 'preto', 'nosso', 'malta', 'bando', 'aonde', 'magia', 'arcar', 'quase',
            'copia', 'venal', 'fatos', 'logro', 'longe', 'sinha', 'aviao', 'afora', 'psico', 'hacia',
            'fatal', 'dessa', 'certa', 'praga', 'sexta', 'quota', 'nivel', 'fixar', 'oasis', 'mente',
            'glosa', 'messe', 'nodoa', 'apelo', 'lidar', 'apego', 'pompa', 'perda', 'verve', 'parca',
            'tocar', 'alado', 'coito', 'jirau', 'caixa', 'livre', 'fraco', 'sumir', 'porta', 'tinha',
            'vezes', 'grave', 'soldo', 'firme', 'lindo', 'bater', 'canon', 'opaco', 'solto', 'irmao',
            'besta', 'faixa', 'astro', 'salve', 'atual', 'elite', 'turva', 'trupe', 'virao', 'doido',
            'supra', 'navio', 'fenda', 'deixa', 'cioso', 'junco', 'grata', 'alcar', 'pardo', 'autor',
            'curso', 'pique', 'chato', 'bioma', 'parva', 'exijo', 'douto', 'bicho', 'aluno', 'macio',
            'desta', 'pagao', 'viria', 'etico', 'reses', 'menos', 'cousa', 'caber', 'calda', 'posto',
            'ficha', 'radio', 'video', 'culpa', 'abuso', 'locus', 'lapis', 'supor', 'zelar', 'gosto',
            'judeu', 'super', 'suave', 'verba', 'calao', 'advir', 'agudo', 'drops', 'extra', 'baixo',
            'julia', 'molho', 'retem', 'torso', 'prive', 'piada', 'facho', 'sitio', 'ruina', 'peito',
            'vinha', 'vosso', 'turma', 'igneo', 'passa', 'traco', 'podio', 'asilo', 'avida', 'estio',
            'combo', 'pilar', 'light', 'orfao', 'turvo', 'chama', 'mosto', 'pareo', 'museu', 'acoes',
            'louca', 'refem', 'amena', 'poeta', 'lasso', 'acola', 'pisar', 'forem', 'brabo', 'mesma',
            'local', 'medir', 'drama', 'optar', 'busca', 'meigo', 'teste', 'ereto', 'finda', 'metie',
            'poema', 'clima', 'tento', 'aviso', 'cutis', 'folga', 'autos', 'geral', 'coral', 'surja',
            'facto', 'cocho', 'hobby', 'rumor', 'amiga', 'rouca', 'feroz', 'tacha', 'paira', 'calmo',
            'pedra', 'idoso', 'cetro', 'rubro', 'boato', 'pacto', 'volta', 'urgia', 'acude', 'golpe',
            'movel', 'licao', 'feudo', 'crime', 'monge', 'ecoar', 'ateia', 'corso', 'manga', 'daqui',
            'ebano', 'riste', 'clean', 'artur', 'carta', 'casar', 'ponha', 'tetra', 'natal', 'falha',
            'benca', 'monte', 'saldo', 'aroma', 'verde', 'conto', 'escol', 'cacho', 'vetar', 'itens',
            'briga', 'hoste', 'vigia', 'tarde', 'grama', 'tribo', 'plumo', 'forum', 'manga', 'pasmo',
            'ornar', 'letal', 'amada', 'fazia', 'troca', 'vento', 'pedro', 'sucia', 'sosia', 'chefe',
            'unica', 'civil', 'rival', 'fruto', 'uteis', 'nuvem', 'orgao', 'pinho', 'tchau', 'plaga',
            'roupa', 'jogar', 'venha', 'sarau', 'vazao', 'areia', 'jejum', 'atimo', 'plebe', 'penta',
            'berro', 'virar', 'arado', 'nesse', 'swing', 'cargo', 'cover', 'seixo', 'fosso', 'perto',
            'midia', 'catre', 'lesse', 'macro', 'stand', 'magna', 'giria', 'rocha', 'axila', 'tutor',
            'legua', 'beijo', 'varoa', 'bruto', 'todas', 'tiver', 'ticao', 'finjo', 'farta', 'inter',
            'troca', 'calor', 'renda', 'bruta', 'pomar', 'assar', 'tenha', 'tenho', 'traje', 'gabar',
            'deste', 'close', 'santo', 'arfar', 'xucro', 'vadio', 'danca', 'trato', 'surto', 'estro',
            'porte', 'amado', 'nessa', 'ambar', 'guria', 'verao', 'perco', 'silvo', 'logos', 'rural',
            'viram', 'odiar', 'feita', 'chula', 'mamae', 'agape', 'vista', 'aviar', 'cenho', 'depor',
            'laudo', 'nesta', 'canto', 'marca', 'negar', 'vedar', 'etapa', 'bazar', 'fossa', 'bolsa',
            'grota', 'salmo', 'cerca', 'pavor', 'canso', 'minar', 'densa', 'cheia', 'cifra', 'recem',
            'coroa', 'irado', 'urdir', 'regio', 'clero', 'visto', 'quais', 'cinto', 'vagar', 'letra',
            'ferpa', 'burra', 'horto', 'sofia', 'ruido', 'jazer', 'inves', 'esgar', 'bucho', 'largo',
            'folha', 'molde', 'proto', 'segar', 'simio', 'sotao', 'lesao', 'paiol', 'final', 'pugna',
            'ubere', 'trago', 'fundo', 'velha', 'penso', 'lesto', 'farol', 'morfo', 'queda', 'narco',
            'alamo', 'vasta', 'ufano', 'ardis', 'pasma', 'olhos', 'linha', 'ceita', 'troco', 'podar',
            'apear', 'piche', 'deram', 'folia', 'preco', 'audio', 'polis', 'umido', 'bulir', 'viger',
            'frota', 'outra', 'mocho', 'neste', 'peita', 'disso', 'chave', 'monta', 'ileso', 'cosmo',
            'matar', 'resto', 'seiva', 'manto', 'chaga', 'redor', 'falsa', 'barro', 'misto', 'retro',
            'mover', 'bolso', 'sacar', 'limpo', 'vazia', 'civel', 'labia', 'bedel', 'campa', 'louca',
            'nariz', 'veloz', 'barao', 'nacar', 'louro', 'samba', 'logia', 'sabor', 'justa', 'toque',
            'mimar', 'lutar', 'album', 'dados', 'banto', 'lucro', 'macho', 'gemer', 'zumbi', 'axial',
            'longo', 'coevo', 'porca', 'punha', 'pagar', 'arroz', 'rente', 'diabo', 'enjoo', 'calca',
            'subir', 'salva', 'farto', 'urgir', 'findo', 'lousa', 'xampu', 'calvo', 'venho', 'valer',
            'baixa', 'pluma', 'focar', 'ousar', 'fatuo', 'sabia', 'bruxa', 'sexto', 'hifen', 'firma',
            'repor', 'sigla', 'pular', 'torna', 'forro', 'lento', 'cardo', 'solta', 'choca', 'corte',
            'bugre', 'gueto', 'feira', 'reler', 'voila', 'custo', 'fugiu', 'tenis', 'corar', 'fazes',
            'vario', 'mania', 'nesga', 'sadio', 'demao', 'canil', 'racio', 'ferir', 'versa', 'modal',
            'harem', 'socio', 'miope', 'puido', 'sugar', 'digna', 'ceifa', 'tumba', 'patio', 'abaco',
            'abada', 'abade', 'abafa', 'abafo', 'abalo', 'abano', 'abate', 'abono', 'abril', 'acaju',
            'acaro', 'aceno', 'acido', 'acuar', 'adaga', 'adega', 'adido', 'adobe', 'adubo', 'aedes',
            'aerar', 'aereo', 'afear', 'afega', 'afiar', 'afogo', 'afoxe', 'agata', 'agave', 'agito',
            'agogo', 'agora', 'aguar', 'aguas', 'aguca', 'aguia', 'aipim', 'aipos', 'aldea', 'alema',
            'aliar', 'altar', 'altos', 'amapa', 'ameba', 'amido', 'amina', 'amino', 'amora', 'andas',
            'anexa', 'anglo', 'angra', 'anima', 'anime', 'anion', 'anodo', 'anual', 'anzol', 'aorta',
            'apaga', 'apara', 'apito', 'apolo', 'apuro', 'arabe', 'arame', 'arara', 'arder', 'arear',
            'areca', 'arena', 'arnes', 'arpao', 'artes', 'aruba', 'ataca', 'atado', 'atear', 'atica',
            'atico', 'ativa', 'atlas', 'atona', 'atono', 'atriz', 'aveia', 'avela', 'avena', 'azara',
            'azeda', 'azedo', 'babao', 'babar', 'babau', 'babel', 'bacia', 'bacon', 'baeta', 'bafio',
            'bagre', 'baiao', 'baila', 'baile', 'baita', 'balao', 'balar', 'balde', 'balsa', 'bamba',
            'bambo', 'bambu', 'banca', 'banco', 'banda', 'banho', 'banir', 'banjo', 'bantu', 'baque',
            'barba', 'barca', 'barco', 'barda', 'barra', 'basto', 'batel', 'batom', 'beato', 'beber',
            'bebes', 'bebum', 'beija', 'beira', 'belga', 'bemol', 'bento', 'beque', 'berco', 'berra',
            'biela', 'bilha', 'bingo', 'biota', 'birma', 'bisao', 'bispo', 'blefe', 'bloco', 'blusa',
            'boate', 'bobar', 'bocal', 'bocha', 'bocio', 'boina', 'bolao', 'bolar', 'bolas', 'boldo',
            'bolha', 'bolor', 'bomba', 'bonde', 'borax', 'borda', 'bordo', 'borla', 'borra', 'boson',
            'botao', 'botim', 'botox', 'braca', 'braco', 'braga', 'brama', 'brasa', 'brita', 'broca',
            'broto', 'broxa', 'bruxo', 'bucal', 'bucha', 'bufao', 'bufar', 'bugio', 'bujao', 'bulbo',
            'buque', 'buril', 'busto', 'butim', 'buzio', 'cabra', 'cacao', 'cacau', 'cacto', 'caiar',
            'caida', 'caido', 'cairo', 'calar', 'calce', 'calco', 'caldo', 'calha', 'calix', 'calva',
            'cambo', 'canal', 'canja', 'canoa', 'cante', 'capao', 'capar', 'capim', 'capuz', 'caqui',
            'carga', 'carie', 'caril', 'carne', 'carne', 'carpa', 'carpo', 'casao', 'casca', 'casco',
            'caspa', 'cassa', 'catar', 'caule', 'causo', 'cauto', 'cavar', 'cedro', 'cegar', 'celta',
            'cento', 'cerar', 'cerco', 'cerda', 'cerva', 'cervo', 'cesto', 'cetim', 'cevar', 'chale',
            'chapa', 'chega', 'chiar', 'chico', 'chile', 'chili', 'china', 'chino', 'chita', 'choca',
            'choco', 'chope', 'chora', 'choro', 'chule', 'chupa', 'chuta', 'chute', 'ciano', 'cidra',
            'cilio', 'cinco', 'cinta', 'cinza', 'circo', 'cirio', 'cisco', 'cisne', 'cisto', 'clama',
            'clara', 'clave', 'clipe', 'clone', 'cloro', 'clube', 'coach', 'cobra', 'cobre', 'coice',
            'coifa', 'coiso', 'colar', 'colmo', 'colon', 'color', 'conde', 'conga', 'copas', 'coque',
            'corca', 'corco', 'corda', 'cores', 'corno', 'corra', 'corre', 'corro', 'corsa', 'corta',
            'corvo', 'costa', 'costo', 'cotar', 'cotas', 'cotia', 'coura', 'couro', 'couto', 'couve',
            'coxim', 'crack', 'cravo', 'crawl', 'creme', 'crepe', 'crina', 'cromo', 'crono', 'crush',
            'cubar', 'cueca', 'cuica', 'curar', 'curia', 'curry', 'curta', 'curto', 'curva', 'curvo',
            'cusco', 'cuspe', 'cuspo', 'custa', 'cutia', 'damas', 'danar', 'dandi', 'danes', 'dardo',
            'datar', 'dedao', 'dedar', 'deita', 'delas', 'delta', 'dente', 'deque', 'derbi', 'derby',
            'derma', 'derme', 'deusa', 'diaba', 'diada', 'diade', 'diana', 'dieta', 'dinar', 'dinda',
            'dingo', 'diodo', 'dique', 'disco', 'disto', 'ditar', 'doado', 'dobar', 'dobra', 'dobre',
            'dobro', 'dodoi', 'doida', 'dolar', 'dolma', 'dolor', 'domar', 'donde', 'dores', 'dorna',
            'dosar', 'dotar', 'draga', 'drink', 'drive', 'droga', 'drone', 'duble', 'ducal', 'ducha',
            'ducto', 'duelo', 'dueto', 'dupla', 'duplo', 'duque', 'durao', 'durar', 'duzia', 'edipo',
            'edito', 'egito', 'ejeto', 'emoji', 'emulo', 'enves', 'envio', 'epica', 'erbio', 'ergio',
            'errar', 'espia', 'esqui', 'esses', 'estai', 'ester', 'estou', 'etano', 'eteno', 'ethos',
            'etila', 'etilo', 'etimo', 'facao', 'fadar', 'falaz', 'falda', 'falho', 'falir', 'falto',
            'falua', 'fanal', 'farda', 'farsi', 'fatao', 'fatia', 'fauce', 'fauno', 'febre', 'fecal',
            'fecha', 'fecho', 'feder', 'fedor', 'felpa', 'femea', 'femeo', 'femur', 'fenil', 'fenix',
            'fenol', 'ferra', 'ferro', 'ferry', 'fetal', 'fezes', 'fiada', 'fiado', 'fiapo', 'fibra',
            'fieis', 'filao', 'filar', 'filha', 'filho', 'filme', 'finar', 'finca', 'fines', 'finta',
            'finto', 'fique', 'fisco', 'fisga', 'flama', 'flame', 'flash', 'flexo', 'floco', 'fluor',
            'flush', 'fobia', 'focal', 'fofao', 'fogao', 'fogos', 'foice', 'folio', 'fonia', 'forca',
            'forja', 'forno', 'forra', 'forro', 'fosca', 'fosco', 'foste', 'foton', 'fovea', 'foyer',
            'fraca', 'frade', 'fraga', 'frear', 'freio', 'fresa', 'frete', 'frevo', 'frisa', 'friso',
            'frita', 'frito', 'front', 'fruta', 'fujao', 'fular', 'fulvo', 'fumar', 'funda', 'funde',
            'funga', 'fungo', 'funil', 'furao', 'furar', 'furia', 'furna', 'furta', 'furto', 'fusca',
            'fusco', 'fuzil', 'gabao', 'gaita', 'galao', 'gales', 'galga', 'galgo', 'galha', 'galho',
            'galio', 'gamao', 'gamar', 'gamba', 'ganga', 'ganho', 'ganir', 'gansa', 'ganso', 'garca',
            'garco', 'garfa', 'garfo', 'garoa', 'garra', 'garua', 'gases', 'gasto', 'gavea', 'gelar',
            'gemeo', 'gesso', 'gesta', 'gibao', 'ginga', 'girar', 'glace', 'glacê', 'globo', 'glote',
            'gnose', 'goela', 'golfe', 'golfo', 'gongo', 'gordo', 'gorja', 'gorro', 'gosma', 'gozar',
            'grade', 'grado', 'grafo', 'grana', 'graxa', 'green', 'grega', 'grego', 'greve', 'grife',
            'grifo', 'grill', 'grita', 'grito', 'grude', 'gruta', 'guano', 'guapo', 'guara', 'guiao',
            'guiar', 'guine', 'guita', 'guizo', 'harpa', 'hedge', 'helio', 'helix', 'hepta', 'herma',
            'hertz', 'hidra', 'hidro', 'hiena', 'hindi', 'hindu', 'honor', 'horas', 'horta', 'hotel',
            'hulha', 'humus', 'hurra', 'husky', 'ilheu', 'ilhos', 'iluso', 'imago', 'imame', 'india',
            'infra', 'ingua', 'input', 'iogue', 'iscar', 'islao', 'istmo', 'itrio', 'jacto', 'janta',
            'jante', 'japao', 'jarda', 'jarra', 'jarro', 'jaspe', 'jaula', 'jeans', 'jegue', 'jeova',
            'jeque', 'jesus', 'jetom', 'jihad', 'jogue', 'joias', 'jongo', 'jorra', 'jorro', 'joule',
            'judas', 'judia', 'juiza', 'julho', 'jumbo', 'junca', 'junho', 'junta', 'jurar', 'kanji',
            'karma', 'kebab', 'kendo', 'khmer', 'kraft', 'krill', 'labil', 'labio', 'lacre', 'ladra',
            'ladro', 'lagoa', 'lajem', 'lanca', 'lance', 'laque', 'larva', 'lasca', 'laser', 'latao',
            'latex', 'latim', 'latir', 'lauda', 'lebre', 'legar', 'lemur', 'lenco', 'lenha', 'lenho',
            'lente', 'leoas', 'leque', 'lerdo', 'lesar', 'lesma', 'leste', 'letao', 'lhama', 'lhano',
            'limao', 'limar', 'limpa', 'lince', 'linfa', 'linho', 'lirio', 'lista', 'litio', 'litro',
            'livra', 'lixao', 'lixar', 'lobby', 'locao', 'locar', 'login', 'logon', 'loira', 'loiro',
            'lomba', 'lombo', 'longa', 'lorde', 'lotar', 'lotus', 'loura', 'lumen', 'lunar', 'lupas',
            'lupus', 'luteo', 'luvas', 'luxar', 'luzir', 'macom', 'madre', 'mafia', 'magma', 'magno',
            'magra', 'magro', 'major', 'malar', 'malha', 'malho', 'malte', 'malva', 'mamao', 'mamar',
            'manco', 'manda', 'mando', 'manta', 'marco', 'maria', 'marra', 'marta', 'marte', 'match',
            'meada', 'meado', 'mecha', 'media', 'medio', 'melao', 'melar', 'menor', 'menta', 'mento',
            'meson', 'metal', 'meter', 'metro', 'metra', 'miada', 'miado', 'micra', 'micro', 'migar',
            'milha', 'milho', 'miolo', 'mioma', 'mirar', 'mirim', 'mirra', 'missa', 'misso', 'mitra',
            'miudo', 'mixar', 'mixer', 'mobil', 'modem', 'moeda', 'moela', 'mofar', 'mofos', 'mogno',
            'moido', 'moita', 'molar', 'molha', 'molhe', 'monja', 'morbo', 'morna', 'morno', 'morsa',
            'morse', 'morto', 'mosca', 'motel', 'motor', 'moura', 'mouro', 'mouse', 'mudez', 'mufla',
            'mumia', 'munir', 'mural', 'murar', 'murro', 'murta', 'musgo', 'musse', 'mutum', 'nacre',
            'nadar', 'nafta', 'naipe', 'nardo', 'nasal', 'nauta', 'naval', 'negra', 'nervo', 'netos',
            'neura', 'neuro', 'nevao', 'nevar', 'nevoa', 'ninfa', 'ninho', 'ninja', 'nisei', 'nisso',
            'nisto', 'nitro', 'niveo', 'nobel', 'nodal', 'noiva', 'noivo', 'norte', 'notar', 'novel',
            'novos', 'nubil', 'nudez', 'nurse', 'nylon', 'obeso', 'obrar', 'oculo', 'odean', 'oeste',
            'ofega', 'ofego', 'ogiva', 'oitao', 'olear', 'ombro', 'omega', 'opala', 'opera', 'orcar',
            'orgia', 'orixa', 'orlar', 'osseo', 'ossos', 'ostra', 'otico', 'otite', 'ovada', 'ovado',
            'ovino', 'ovulo', 'oxido', 'padre', 'pager', 'palco', 'palha', 'palio', 'palma', 'palmo',
            'palpo', 'pampa', 'panda', 'papai', 'papal', 'papao', 'papar', 'parda', 'parir', 'parka',
            'parra', 'parto', 'passe', 'pasta', 'pasto', 'patao', 'patua', 'pausa', 'pavao', 'pavio',
            'pecar', 'pedal', 'pegao', 'pejar', 'pelar', 'pente', 'perca', 'perla', 'perna', 'perro',
            'persa', 'perua', 'pesca', 'piaba', 'piano', 'picar', 'picho', 'picto', 'piela', 'pifao',
            'pifar', 'pilao', 'pilha', 'pinca', 'pingo', 'pinha', 'pinta', 'pinto', 'piora', 'pirao',
            'pirar', 'pires', 'pisca', 'pisco', 'pista', 'pitao', 'pitar', 'plato', 'plexo', 'plush',
            'pocao', 'podre', 'polca', 'polen', 'polia', 'polio', 'polir', 'polme', 'polpa', 'polvo',
            'pomba', 'pombo', 'ponta', 'ponte', 'porao', 'porco', 'porre', 'porto', 'posar', 'poser',
            'posta', 'poste', 'potra', 'potro', 'pouca', 'poupa', 'pousa', 'pouso', 'praca', 'prata',
            'prato', 'prega', 'prego', 'prelo', 'prima', 'primo', 'prior', 'priva', 'prono', 'provo',
            'pubis', 'pudim', 'pugil', 'pulga', 'pulso', 'punga', 'punho', 'punir', 'purga', 'puxao',
            'quark', 'quati', 'quedo', 'queen', 'quepe', 'quibe', 'quilo', 'quina', 'quita', 'quite',
            'quito', 'quivi', 'racao', 'racha', 'radar', 'ragla', 'raiar', 'raide', 'raiom', 'raios',
            'rajar', 'ralar', 'ralho', 'rally', 'ramal', 'ramos', 'rampa', 'rango', 'ranho', 'rapar',
            'rapel', 'rapto', 'raque', 'rasar', 'rasgo', 'raspa', 'rasta', 'rasto', 'ratao', 'razia',
            'reata', 'recta', 'recto', 'recua', 'recuo', 'redea', 'redox', 'refez', 'refil', 'regar',
            'regia', 'regua', 'reich', 'reiki', 'relax', 'relha', 'remar', 'remix', 'renal', 'repto',
            'resma', 'retal', 'retor', 'retos', 'retro', 'reuma', 'revir', 'ricar', 'rifar', 'rifle',
            'rimar', 'rimas', 'rimel', 'rinha', 'ripar', 'risca', 'rixar', 'rocio', 'rodar', 'rodio',
            'roido', 'rojao', 'rolar', 'rolha', 'rombo', 'romeu', 'ronca', 'ronco', 'ronda', 'ronha',
            'roque', 'rosar', 'rosca', 'roseo', 'rosto', 'rotar', 'rotor', 'roubo', 'rouco', 'rouge',
            'rublo', 'rubor', 'rudez', 'ruela', 'rufar', 'rugar', 'rugbi', 'rugby', 'rugir', 'ruiva',
            'ruivo', 'rumar', 'rumba', 'rumen', 'rupia', 'rusga', 'russo', 'sabao', 'sabre', 'cache',
            'sacro', 'safar', 'safra', 'saido', 'salao', 'salga', 'salsa', 'salto', 'sanha', 'santa',
            'saque', 'sarar', 'sarda', 'sardo', 'sarja', 'sarna', 'sarro', 'sauna', 'sauna', 'sauva',
            'secar', 'sedan', 'sedar', 'selim', 'selva', 'semen', 'senha', 'sepia', 'septo', 'serra',
            'serva', 'sheik', 'short', 'shoyu', 'sidra', 'sifao', 'sigma', 'signa', 'silex', 'silfa',
            'silfo', 'silva', 'simil', 'siria', 'sirio', 'sisal', 'sismo', 'skate', 'slack', 'slide',
            'soada', 'sobra', 'socar', 'sodio', 'sogra', 'sogro', 'solar', 'solda', 'somar', 'sonar',
            'sonda', 'sopor', 'sopro', 'sorgo', 'sorva', 'sousa', 'sovar', 'spray', 'staff', 'still',
            'strip', 'suado', 'sucre', 'sueca', 'sueco', 'suede', 'sufle', 'suica', 'suico', 'suino',
            'suite', 'sujar', 'sulfa', 'supra', 'surda', 'surdo', 'surfe', 'surra', 'sushi', 'susto',
            'sutia', 'sutra', 'swell', 'tabla', 'tabua', 'tacao', 'tacar', 'tacho', 'tacto', 'taifa',
            'taipa', 'talao', 'talar', 'talco', 'talha', 'talhe', 'talho', 'tampa', 'tampo', 'tanga',
            'tango', 'tapar', 'tapir', 'tapiz', 'tarar', 'tardo', 'tarja', 'tarso', 'tatos', 'tatui',
            'taxar', 'taxis', 'taxon', 'tecla', 'tecno', 'teima', 'teina', 'teipe', 'telao', 'telar',
            'telex', 'telha', 'telho', 'temao', 'tenda', 'tenia', 'tenor', 'tensa', 'tense', 'tenta',
            'tente', 'tergo', 'tesar', 'tesla', 'testa', 'testo', 'tetas', 'tiara', 'tibia', 'tibio',
            'ticar', 'tiete', 'tifao', 'tigre', 'tilia', 'timao', 'tinir', 'tinta', 'tinto', 'tipoi',
            'tique', 'tirao', 'titia', 'titio', 'tocha', 'togas', 'tolda', 'toldo', 'tolho', 'tomba',
            'tombo', 'tonal', 'tonar', 'tonel', 'toner', 'tonga', 'tonta', 'tonto', 'tonus', 'topar',
            'topaz', 'torax', 'torce', 'tordo', 'torno', 'torra', 'torre', 'torta', 'torto', 'tosse',
            'total', 'totem', 'touca', 'touro', 'traca', 'trair', 'trans', 'trapa', 'trapo', 'trava',
            'trave', 'travo', 'treco', 'trela', 'trema', 'trena', 'treno', 'treno', 'trepa', 'treva',
            'trevo', 'treze', 'triar', 'trico', 'trigo', 'trino', 'tripa', 'tripe', 'trole', 'trono',
            'tropa', 'trote', 'trova', 'trufa', 'truta', 'tufao', 'tufar', 'tulha', 'tumor', 'tunel',
            'tunga', 'turbo', 'turca', 'turco', 'turfa', 'turfe', 'turne', 'turno', 'turra', 'tweed',
            'twist', 'uivar', 'ultra', 'umero', 'uncao', 'ungir', 'unido', 'untar', 'urano', 'ureia',
            'ureia', 'urico', 'urina', 'urrar', 'urubu', 'urutu', 'usado', 'usina', 'usque', 'usual',
            'utero', 'uvula', 'vagao', 'vagem', 'vaiar', 'valeu', 'valsa', 'vapor', 'varal', 'varar',
            'varia', 'variz', 'vasco', 'vazar', 'veado', 'venda', 'venta', 'venus', 'veras', 'veraz',
            'verga', 'verme', 'vespa', 'veste', 'vexar', 'vidao', 'vidro', 'viela', 'vigil', 'vilar',
            'vinca', 'vinco', 'vinda', 'vindo', 'vinho', 'vinil', 'vinte', 'viola', 'viral', 'virgo',
            'visco', 'visgo', 'visom', 'visor', 'viuva', 'viuvo', 'voada', 'vocal', 'vodca', 'vogal',
            'vogar', 'volei', 'votar', 'xerox', 'xerox', 'xogum', 'zanga', 'zebra', 'zerar', 'ziper',
            'zonzo', 'zorra', 'zorro', 'zunir',
        ];
    }
}