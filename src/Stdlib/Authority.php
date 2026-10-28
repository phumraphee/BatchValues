<?php
namespace BatchValues\Stdlib;

use Interop\Container\ContainerInterface;

/**
 * Asks authority services for the label of a link, or for the link of a text
 * (1.1.0). Read-only: nothing here writes to Omeka.
 *
 * Two ways to ask, the first that answers wins:
 *
 *  - Value Suggest, when that module is on: its own suggesters, with their
 *    own addresses and settings, are asked with the text (a link of a
 *    "suggest all" list is looked up in the whole list). A link is found from
 *    a text only when exactly one suggestion has that text.
 *  - The link itself, for the services listed in dataUrl(): its machine-
 *    readable page (JSON or RDF) is read for its name. No other address is
 *    ever fetched, so a value cannot make the server open any page.
 *
 * Every answer is kept for the rest of the run (and, by the operation, with
 * the change set), so the run writes what the preview showed and asks each
 * question once; the run itself asks nothing new (freeze()). A preview asks
 * at most MAX_LOOKUPS questions, for at most MAX_SECONDS; after three
 * failures in a row the service is taken as down for the rest of it.
 */
class Authority
{
    /** Questions sent per run (the rest are left for another run). */
    const MAX_LOOKUPS = 200;

    /** Seconds to wait for one answer. */
    const TIMEOUT = 8;

    /** Seconds of asking per request, so a slow service cannot hold the page. */
    const MAX_SECONDS = 90;

    /** Failures in a row after which no more questions are sent. */
    const MAX_FAILURES = 3;

    /** Answers kept with a change set. */
    const MAX_KEPT = 3000;

    protected $services;

    /**
     * @var callable|null fn(string $url, string $accept): ?array ['status' => int, 'body' => string]
     */
    protected $fetcher;

    protected $answers = [];

    /** The language of the label pickLabel() chose last (null: none known). */
    protected static $pickedLang;

    /** Suggestions per question, for this run only. */
    protected $suggested = [];

    protected $asked = 0;

    protected $failures = 0;

    /** When the first question was sent. */
    protected $started;

    /** Only the answers known are used: nothing is asked (the run). */
    protected $frozen = false;

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
    }

    /** Another way to fetch a page (tests). */
    public function setFetcher(?callable $fetcher): self
    {
        $this->fetcher = $fetcher;
        return $this;
    }

    /** Answers found before (by the preview), used without asking again. */
    public function preload(array $answers): self
    {
        $this->answers = $answers + $this->answers;
        return $this;
    }

    /**
     * Ask nothing new: a value the preview did not look up is left, with
     * the reason "later", so the run never writes what the preview did not
     * show.
     */
    public function freeze(bool $frozen = true): self
    {
        $this->frozen = $frozen;
        return $this;
    }

    /** The answers known, to keep with the change set. */
    public function answers(): array
    {
        // "No service to ask" is found again without asking: not kept.
        $kept = array_filter($this->answers, function ($answer) {
            return ($answer['error'] ?? null) !== 'unknown';
        });
        return array_slice($kept, 0, self::MAX_KEPT, true);
    }

    /** How many questions were sent (tests, notes). */
    public function asked(): int
    {
        return $this->asked;
    }

    /** Data types whose values can hold a link to an authority. */
    public static function isAuthorityType(?string $type): bool
    {
        return $type === 'uri' || Planner::isValueSuggest($type);
    }

    public static function hasValueSuggest(): bool
    {
        return interface_exists('ValueSuggest\DataType\DataTypeInterface');
    }

    /**
     * A link written so that two ways of writing the same one compare equal:
     * no scheme, no "www.", no slash or "#" at the end, host in lower case.
     */
    public static function uriKey(string $uri): string
    {
        $uri = trim($uri);
        if (!preg_match('~^[a-z][a-z0-9+.-]*://([^/?#]+)(.*)$~i', $uri, $m)) {
            return $uri;
        }
        $host = preg_replace('~^www\.~', '', strtolower($m[1]));
        return $host . rtrim($m[2], '/#');
    }

    public static function sameUri(string $a, string $b): bool
    {
        return self::uriKey($a) === self::uriKey($b);
    }

    /** Text compared without case, spaces at the ends or double spaces. */
    public static function labelKey(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text));
        if (class_exists('Normalizer')) {
            $text = (string) \Normalizer::normalize($text, \Normalizer::FORM_C);
        }
        return mb_strtolower($text);
    }

    /**
     * The label of a link.
     *
     * @return array ['label' => ?string, 'error' => ?string, 'lang' => ?string]
     *   lang: the language of the label when the service says it ("" for
     *   none), null when it is not known; error: "none"
     *   (the service has no name for it), "unknown" (no service to ask),
     *   "limit" (too many questions or too long), "down" (no answer),
     *   "later" (not looked up in the preview)
     */
    public function label(string $uri, string $type, ?string $current, string $lang = ''): array
    {
        $uri = trim($uri);
        $key = 'l|' . $type . '|' . $uri . '|' . $lang;
        if (array_key_exists($key, $this->answers)) {
            return $this->answers[$key];
        }
        $asked = false;
        $result = null;
        // Value Suggest first: its own services, as configured there.
        if (Planner::isValueSuggest($type) && $this->suggester($type)) {
            $isAll = strpos($type, 'valuesuggestall:') === 0;
            $query = $isAll ? '' : trim((string) $current);
            if ($isAll || ($query !== '' && !self::sameUri($query, $uri))) {
                $suggestions = $this->suggestions($type, $query, $lang);
                if (isset($suggestions['error'])) {
                    return $suggestions + ['label' => null];
                }
                $asked = true;
                foreach ($suggestions as $s) {
                    if (self::sameUri((string) ($s['uri'] ?? ''), $uri) && trim((string) $s['value']) !== '') {
                        $result = trim((string) $s['value']);
                        break;
                    }
                }
            }
        }
        $resultLang = null;
        if ($result === null) {
            $data = self::dataUrl($uri);
            if ($data) {
                $page = $this->fetch($data['url'], $data['accept']);
                if (isset($page['error'])) {
                    return $page + ['label' => null];
                }
                $asked = true;
                self::$pickedLang = null;
                $result = $page['body'] === null ? null : self::labelFromPage($data['kind'], $page['body'], $uri, $lang);
                $resultLang = $result === null ? null : self::$pickedLang;
            }
        }
        $answer = $result !== null ? ['label' => $result, 'error' => null, 'lang' => $resultLang] : ['label' => null, 'error' => $asked ? 'none' : 'unknown'];
        return $this->answers[$key] = $answer;
    }

    /**
     * The link of a text, through the Value Suggest data type $type: found
     * only when exactly one suggestion has the same text (case and spaces
     * aside), or, with $loose, when there is only one suggestion at all.
     *
     * @return array ['uri' => ?string, 'label' => ?string, 'count' => int, 'error' => ?string]
     *   error: "none", "several", "inexact" (suggestions, none with this
     *   text), "unknown", "limit", "down", "later"
     */
    public function uri(string $text, string $type, string $lang = '', bool $loose = false): array
    {
        $text = trim($text);
        $key = 'u|' . $type . '|' . self::labelKey($text) . '|' . $lang . '|' . (int) $loose;
        if (array_key_exists($key, $this->answers)) {
            return $this->answers[$key];
        }
        if ($text === '' || !$this->suggester($type)) {
            return ['uri' => null, 'label' => null, 'count' => 0, 'error' => 'unknown'];
        }
        $suggestions = $this->suggestions($type, strpos($type, 'valuesuggestall:') === 0 ? '' : $text, $lang);
        if (isset($suggestions['error'])) {
            return $suggestions + ['uri' => null, 'label' => null, 'count' => 0];
        }
        $want = self::labelKey($text);
        $same = [];
        foreach ($suggestions as $s) {
            if (($s['uri'] ?? '') !== '' && self::labelKey((string) $s['value']) === $want) {
                $same[self::uriKey($s['uri'])] = $s;
            }
        }
        $withUri = array_values(array_filter($suggestions, function ($s) {
            return ($s['uri'] ?? '') !== '';
        }));
        if (count($same) === 1) {
            $s = reset($same);
        } elseif (!$same && $loose && count($withUri) === 1) {
            $s = $withUri[0];
        } else {
            $s = null;
        }
        $answer = $s
            ? ['uri' => (string) $s['uri'], 'label' => (string) $s['value'], 'count' => count($same) ?: 1, 'error' => null]
            : ['uri' => null, 'label' => null, 'count' => count($same) ?: count($withUri), 'error' => count($same) > 1 ? 'several' : ($withUri ? 'inexact' : 'none')];
        return $this->answers[$key] = $answer;
    }

    // ------------------------------------------------------------------
    // Value Suggest
    // ------------------------------------------------------------------

    /**
     * The Value Suggest suggester of a data type, or null.
     */
    protected function suggester(string $type)
    {
        if (!self::hasValueSuggest()) {
            return null;
        }
        $dataTypes = $this->services->get('Omeka\DataTypeManager');
        if (!$dataTypes->has($type)) {
            return null;
        }
        $dataType = $dataTypes->get($type);
        if (!$dataType instanceof \ValueSuggest\DataType\DataTypeInterface) {
            return null;
        }
        return $dataType->getSuggester();
    }

    /**
     * Suggestions as [['value' => string, 'uri' => ?string]], or ['error' => …].
     */
    protected function suggestions(string $type, string $query, string $lang): array
    {
        $key = $type . '|' . self::labelKey($query) . '|' . $lang;
        if (isset($this->suggested[$key])) {
            return $this->suggested[$key];
        }
        $stop = $this->mayAsk();
        if ($stop) {
            return $stop;
        }
        ++$this->asked;
        try {
            $list = $this->suggester($type)->getSuggestions($query, $lang === '' ? null : $lang);
            $this->failures = 0;
        } catch (\Throwable $e) {
            ++$this->failures;
            return ['error' => 'down'];
        }
        $out = [];
        foreach (is_array($list) ? $list : [] as $s) {
            if (isset($s['value']) && is_scalar($s['value'])) {
                $out[] = ['value' => (string) $s['value'], 'uri' => isset($s['data']['uri']) && is_scalar($s['data']['uri']) ? trim((string) $s['data']['uri']) : null];
            }
        }
        return $this->suggested[$key] = $out;
    }

    // ------------------------------------------------------------------
    // The link's own page
    // ------------------------------------------------------------------

    /**
     * Where the machine-readable page of a link is, for the services known
     * here; null for any other address.
     *
     * @return array|null ['url', 'accept', 'kind' => jsonld|wikidata|rdf|gnd|orcid|ror]
     */
    public static function dataUrl(string $uri): ?array
    {
        $uri = trim($uri);
        if (!preg_match('~^https?://([^/?#]+)(/[^?#]*)?~i', $uri, $m)) {
            return null;
        }
        $host = preg_replace('~^www\.~', '', strtolower($m[1]));
        $path = rtrim($m[2] ?? '', '/');
        $json = 'application/json';
        $rdf = 'application/rdf+xml';
        if ($host === 'id.loc.gov' && preg_match('~^/(authorities|vocabulary)/[A-Za-z0-9_/-]+(\.(html|json|rdf))?$~', $path)) {
            return ['url' => 'https://id.loc.gov' . preg_replace('~\.(html|json|rdf)$~', '', $path) . '.json', 'accept' => $json, 'kind' => 'jsonld'];
        }
        if ($host === 'wikidata.org' && preg_match('~^/(?:wiki|entity)/(Q\d+)$~', $path, $q)) {
            return ['url' => 'https://www.wikidata.org/wiki/Special:EntityData/' . $q[1] . '.json', 'accept' => $json, 'kind' => 'wikidata'];
        }
        if (in_array($host, ['geonames.org', 'sws.geonames.org'], true) && preg_match('~^/(\d+)(?:/.*)?$~', $path, $q)) {
            return ['url' => 'https://sws.geonames.org/' . $q[1] . '/about.rdf', 'accept' => $rdf, 'kind' => 'rdf'];
        }
        if ($host === 'idref.fr' && preg_match('~^/(\d{8}[\dXx])(?:\.\w+)?$~', $path, $q)) {
            return ['url' => 'https://www.idref.fr/' . strtoupper($q[1]) . '.rdf', 'accept' => $rdf, 'kind' => 'rdf'];
        }
        if ($host === 'vocab.getty.edu' && preg_match('~^/(aat|tgn|ulan)/(\d+)$~', $path, $q)) {
            return ['url' => 'https://vocab.getty.edu/' . $q[1] . '/' . $q[2] . '.rdf', 'accept' => $rdf, 'kind' => 'rdf'];
        }
        if ($host === 'd-nb.info' && preg_match('~^/gnd/([0-9X-]+)$~i', $path, $q)) {
            return ['url' => 'https://lobid.org/gnd/' . $q[1] . '.json', 'accept' => $json, 'kind' => 'gnd'];
        }
        if ($host === 'orcid.org' && preg_match('~^/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])$~', $path, $q)) {
            return ['url' => 'https://pub.orcid.org/v3.0/' . $q[1] . '/person', 'accept' => $json, 'kind' => 'orcid'];
        }
        if ($host === 'ror.org' && preg_match('~^/(0[a-z0-9]{8})$~', $path, $q)) {
            return ['url' => 'https://api.ror.org/v2/organizations/' . $q[1], 'accept' => $json, 'kind' => 'ror'];
        }
        return null;
    }

    /** The services whose links can be read here, for the help text. */
    const KNOWN = 'id.loc.gov, wikidata.org, geonames.org, idref.fr, vocab.getty.edu, d-nb.info (GND), orcid.org, ror.org';

    /**
     * The name a page gives to the link, in the language asked for when it
     * has one.
     */
    public static function labelFromPage(string $kind, string $body, string $uri, string $lang = ''): ?string
    {
        if ($kind === 'rdf') {
            return self::labelFromRdf($body, $uri, $lang);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        $pick = function (array $candidates) use ($lang) {
            return self::pickLabel($candidates, $lang);
        };
        switch ($kind) {
            case 'jsonld':
                $nodes = isset($data['@graph']) ? $data['@graph'] : (isset($data[0]) ? $data : [$data]);
                $predicates = [
                    'http://www.loc.gov/mads/rdf/v1#authoritativeLabel',
                    'http://www.w3.org/2004/02/skos/core#prefLabel',
                    'http://www.w3.org/2000/01/rdf-schema#label',
                ];
                foreach ($nodes as $node) {
                    if (!is_array($node) || !self::sameUri((string) ($node['@id'] ?? ''), $uri)) {
                        continue;
                    }
                    foreach ($predicates as $rank => $predicate) {
                        $candidates = [];
                        foreach ((array) ($node[$predicate] ?? []) as $v) {
                            if (is_array($v) && isset($v['@value'])) {
                                $candidates[] = [(string) $v['@value'], (string) ($v['@language'] ?? ''), $rank];
                            } elseif (is_string($v)) {
                                $candidates[] = [$v, '', $rank];
                            }
                        }
                        $found = $pick($candidates);
                        if ($found !== null) {
                            return $found;
                        }
                    }
                }
                return null;
            case 'wikidata':
                $entity = is_array($data['entities'] ?? null) ? reset($data['entities']) : null;
                $candidates = [];
                foreach ((array) ($entity['labels'] ?? []) as $code => $v) {
                    if (isset($v['value'])) {
                        $candidates[] = [(string) $v['value'], $code === 'mul' ? '' : (string) $code, 0];
                    }
                }
                return $pick($candidates);
            case 'gnd':
                return isset($data['preferredName']) && is_string($data['preferredName']) ? self::clean($data['preferredName']) : null;
            case 'orcid':
                $name = $data['name'] ?? [];
                $credit = $name['credit-name']['value'] ?? null;
                if (is_string($credit) && trim($credit) !== '') {
                    return self::clean($credit);
                }
                $full = trim(($name['given-names']['value'] ?? '') . ' ' . ($name['family-name']['value'] ?? ''));
                return $full !== '' ? self::clean($full) : null;
            case 'ror':
                $candidates = [];
                foreach ((array) ($data['names'] ?? []) as $n) {
                    if (!isset($n['value'])) {
                        continue;
                    }
                    $types = (array) ($n['types'] ?? []);
                    $rank = in_array('ror_display', $types, true) ? 0 : (in_array('label', $types, true) ? 1 : 2);
                    $candidates[] = [(string) $n['value'], (string) ($n['lang'] ?? ''), $rank];
                }
                return $pick($candidates);
        }
        return null;
    }

    /**
     * The name in an RDF/XML page: the description of the link (or the
     * first one), its skos:prefLabel, else gn:name / gn:officialName, else
     * foaf:name / schema:name, else rdfs:label.
     */
    public static function labelFromRdf(string $xml, string $uri, string $lang = ''): ?string
    {
        if (trim($xml) === '') {
            return null;
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // No network and no entities: the page is only read.
        $ok = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok || !$dom->documentElement) {
            return null;
        }
        $rdfNs = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
        $ranks = [
            'http://www.w3.org/2004/02/skos/core#prefLabel' => 0,
            'http://www.geonames.org/ontology#name' => 1,
            'http://www.geonames.org/ontology#officialName' => 2,
            'http://xmlns.com/foaf/0.1/name' => 3,
            'http://schema.org/name' => 3,
            'https://schema.org/name' => 3,
            'http://www.w3.org/2000/01/rdf-schema#label' => 4,
            'http://www.geonames.org/ontology#alternateName' => 5,
        ];
        $descriptions = [];
        foreach ($dom->documentElement->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $descriptions[] = $node;
            }
        }
        $chosen = null;
        foreach ($descriptions as $node) {
            $about = $node->getAttributeNS($rdfNs, 'about');
            if ($about !== '' && self::sameUri($about, $uri)) {
                $chosen = $node;
                break;
            }
        }
        if (!$chosen) {
            $chosen = $descriptions[0] ?? null;
        }
        if (!$chosen) {
            return null;
        }
        $candidates = [];
        foreach ($chosen->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $name = $child->namespaceURI . $child->localName;
            if (!isset($ranks[$name])) {
                continue;
            }
            $text = trim($child->textContent);
            if ($text === '') {
                continue;
            }
            $candidates[] = [$text, $child->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang'), $ranks[$name]];
        }
        return self::pickLabel($candidates, $lang);
    }

    /**
     * The best of [text, lang, rank]: the language asked for (or its main
     * part: "th" for "th-TH"), else no language or English, else any; then
     * the lowest rank, then the first.
     */
    public static function pickLabel(array $candidates, string $lang = ''): ?string
    {
        $picked = self::pickLabelLang($candidates, $lang);
        self::$pickedLang = $picked ? $picked[1] : null;
        return $picked ? $picked[0] : null;
    }

    /**
     * As pickLabel(), with the language of the label chosen: [text, lang]
     * ("" for a label without a language).
     */
    public static function pickLabelLang(array $candidates, string $lang = ''): ?array
    {
        $want = strtolower($lang);
        $wantMain = explode('-', str_replace('_', '-', $want))[0];
        $best = null;
        $bestScore = null;
        foreach ($candidates as $i => [$text, $code, $rank]) {
            $text = self::clean((string) $text);
            if ($text === '') {
                continue;
            }
            $code = strtolower((string) $code);
            $main = explode('-', str_replace('_', '-', $code))[0];
            if ($want !== '' && $code === $want) {
                $l = 0;
            } elseif ($want !== '' && $main === $wantMain) {
                $l = 1;
            } elseif ($code === '' || $main === 'en') {
                $l = 2;
            } else {
                $l = 3;
            }
            $score = [$l, (int) $rank, $i];
            if ($bestScore === null || $score < $bestScore) {
                $best = [$text, $code];
                $bestScore = $score;
            }
        }
        return $best;
    }

    protected static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    // ------------------------------------------------------------------
    // Asking
    // ------------------------------------------------------------------

    /** Null when one more question may be sent, else the reason not to. */
    protected function mayAsk(): ?array
    {
        if ($this->frozen) {
            return ['error' => 'later'];
        }
        if ($this->failures >= self::MAX_FAILURES) {
            return ['error' => 'down'];
        }
        if ($this->started === null) {
            $this->started = microtime(true);
        }
        if ($this->asked >= self::MAX_LOOKUPS || microtime(true) - $this->started > self::MAX_SECONDS) {
            return ['error' => 'limit'];
        }
        return null;
    }

    /**
     * @return array ['body' => ?string] (null: the service has no such page)
     *   or ['error' => 'limit'|'down']
     */
    protected function fetch(string $url, string $accept): array
    {
        $stop = $this->mayAsk();
        if ($stop) {
            return $stop;
        }
        ++$this->asked;
        try {
            $response = $this->fetcher ? ($this->fetcher)($url, $accept) : $this->httpGet($url, $accept);
        } catch (\Throwable $e) {
            $response = null;
        }
        $status = (int) ($response['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            $this->failures = 0;
            return ['body' => (string) ($response['body'] ?? '')];
        }
        if (in_array($status, [404, 410], true)) {
            $this->failures = 0;
            return ['body' => null];
        }
        ++$this->failures;
        return ['error' => 'down'];
    }

    protected function httpGet(string $url, string $accept): array
    {
        $client = $this->services->get('Omeka\HttpClient');
        // Redirects are followed here, not by the client: it sends a port
        // written in the address ("https://host:443/…") on in the Host
        // header, which some services refuse (Getty answers 400).
        for ($hops = 0; ; ++$hops) {
            $client->resetParameters(true);
            $client->setUri($url);
            $client->setOptions(['timeout' => self::TIMEOUT, 'maxredirects' => 0]);
            $client->setHeaders(['Accept' => $accept, 'User-Agent' => 'Omeka S module Batch Values']);
            $response = $client->send();
            $status = $response->getStatusCode();
            $location = $response->getHeaders()->get('Location');
            if ($status < 300 || $status >= 400 || !$location || $hops >= 3) {
                return ['status' => $status, 'body' => $response->getBody()];
            }
            $url = self::redirectUrl($url, $location->getFieldValue());
        }
    }

    /**
     * Where a redirect points, as a whole address without the default port.
     */
    public static function redirectUrl(string $from, string $location): string
    {
        $location = trim($location);
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $location)) {
            $base = parse_url($from);
            $root = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
            $location = $root . (strpos($location, '/') === 0 ? $location : '/' . $location);
        }
        return preg_replace(['~^(https://[^/:?#]+):443(?=[/?#]|$)~i', '~^(http://[^/:?#]+):80(?=[/?#]|$)~i'], '$1', $location);
    }
}
