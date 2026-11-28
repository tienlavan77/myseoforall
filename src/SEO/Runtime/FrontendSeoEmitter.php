<?php
/**
 * Business purpose: publish My SEO For All frontend metadata safely.
 * Technical description: apply fail-closed request and ownership gates, sanitize values, and attach read-only WordPress hooks without database or network access.
 */
declare(strict_types=1);

namespace MySeoForAll\SEO\Runtime;

final class FrontendSeoEmitter
{
    private CanonicalSeoMetaRepository $repository;
    private $context;
    private $ownership;
    private $rankMath;
    private $configuration;
    private $competitors;

    /**
     * Business purpose: inject read-only seams for deterministic frontend decisions.
     * Technical behavior: retain repository and callback dependencies without platform calls.
     */
    public function __construct(CanonicalSeoMetaRepository $repository, ?callable $context = null, ?callable $ownership = null, ?callable $rankMath = null, ?callable $configuration = null, ?callable $competitors = null)
    {
        $this->repository = $repository; $this->context = $context; $this->ownership = $ownership; $this->rankMath = $rankMath; $this->configuration = $configuration; $this->competitors = $competitors;
    }

    /** Business purpose: connect public hooks. Technical behavior: use dispatcher deduplication for title and head listeners. */
    public function register(\MySeoForAll\Core\HookDispatcher $dispatcher): void
    {
        $dispatcher->addFilter('pre_get_document_title', [$this, 'filterTitle'], 20, 1);
        $dispatcher->addAction('wp_head', [$this, 'renderHead'], 5, 1);
    }

    /** Business purpose: replace the document title safely. Technical behavior: preserve input when gates fail or stored text is empty. */
    public function filterTitle($title)
    {
        if (!$this->shouldEmit()) return $title;
        $value = $this->plainText($this->snapshot()['title'] ?? null);
        return $value === '' ? $title : $value;
    }

    /** Business purpose: publish description and canonical metadata. Technical behavior: escape attributes and remove default canonical only after valid replacement. */
    public function renderHead(): void
    {
        if (!$this->shouldEmit()) return;
        $snapshot = $this->snapshot();
        $description = $this->plainText($snapshot['meta_description'] ?? null);
        if ($description !== '') echo '<meta name="description" content="' . $this->escape($description) . '" />' . "\n";
        $canonical = $this->publicUrl($snapshot['canonical'] ?? null);
        if ($canonical === '') return;
        if (function_exists('remove_action')) remove_action('wp_head', 'rel_canonical');
        echo '<link rel="canonical" href="' . $this->escape($canonical) . '" />' . "\n";
    }

    /** Business purpose: prevent duplicate ownership. Technical description: reject unsafe request, plugin, configuration, and ownership states. */
    public function shouldEmit(): bool
    {
        if (function_exists('is_admin') && is_admin()) return false;
        foreach (['wp_doing_ajax', 'wp_doing_cron'] as $guard) if (function_exists($guard) && $guard()) return false;
        if ((function_exists('is_feed') && is_feed()) || (defined('REST_REQUEST') && REST_REQUEST) || (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request())) return false;
        $context = is_callable($this->context) ? ($this->context)() : $this->defaultContext();
        if (!is_array($context) || ($context['post_id'] ?? 0) <= 0 || ($context['status'] ?? '') !== 'publish' || !in_array($context['post_type'] ?? '', ['post', 'page', 'product'], true) || ($context['singular'] ?? false) !== true) return false;
        if (($context['post_type'] ?? '') === 'product' && ($context['woo_available'] ?? false) !== true) return false;
        $rank = is_callable($this->rankMath) ? ($this->rankMath)() : [];
        $activation = is_array($rank) && is_array($rank['activation'] ?? null) ? ($rank['activation']['state'] ?? null) : ($rank['activation'] ?? null);
        if (!in_array($activation, ['absent', 'inactive'], true)) return false;
        $competitors = is_callable($this->competitors) ? ($this->competitors)() : $this->defaultCompetitors();
        if (!is_array($competitors) || $competitors !== []) return false;
        $config = is_callable($this->configuration) ? ($this->configuration)() : [];
        if (!is_array($config) || ($config['rank_math_mode'] ?? null) !== 'disabled') return false;
        $decision = is_callable($this->ownership) ? ($this->ownership)() : [];
        return is_array($decision) && ($decision['owner'] ?? null) === 'myseo_for_all' && ($decision['state'] ?? null) === 'owned' && ($decision['duplicate_risk'] ?? null) === 'none';
    }

    /** Business purpose: read canonical fields. Technical description: resolve a positive post ID and call only the repository snapshot API. */
    private function snapshot(): array
    {
        $context = is_callable($this->context) ? ($this->context)() : $this->defaultContext();
        $value = $this->repository->snapshot((int) ($context['post_id'] ?? 0));
        return is_array($value) ? $value : [];
    }

    /** Business purpose: provide a passive request snapshot. Technical description: use guarded core functions and closed defaults. */
    private function defaultContext(): array
    {
        $post = function_exists('get_post') ? get_post() : null;
        return ['post_id' => is_object($post) ? (int) ($post->ID ?? 0) : 0, 'post_type' => function_exists('get_post_type') ? (string) get_post_type() : '', 'status' => is_object($post) ? (string) ($post->post_status ?? '') : '', 'singular' => function_exists('is_singular') ? is_singular(['post', 'page', 'product']) : false];
    }

    /** Business purpose: identify competing emitters. Technical description: inspect passive version constants without mutation. */
    private function defaultCompetitors(): array
    {
        $active = [];
        foreach (['WPSEO_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION'] as $constant) if (defined($constant)) $active[] = $constant;
        return $active;
    }

    /** Business purpose: remove unsafe markup. Technical description: decode entities, strip tags, collapse whitespace, and remove controls. */
    private function plainText($value): string
    {
        if (!is_string($value)) return '';
        $value = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\\s+/u', ' ', $value) ?? $value);
    }

    /** Business purpose: accept public canonical URLs. Technical description: require HTTP(S), reject credentials, controls, local hosts, and private IP literals without DNS. */
    private function publicUrl($value): string
    {
        if (!is_string($value) || preg_match('/[\\x00-\\x20\\x7F]/', $value)) return '';
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || isset($parts['user'], $parts['pass']) || !isset($parts['host'])) return '';
        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost' || (filter_var($host, FILTER_VALIDATE_IP) && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)) return '';
        return trim($value);
    }

    /** Business purpose: prevent attribute injection. Technical description: use WordPress escaping or UTF-8 HTML encoding. */
    private function escape(string $value): string
    {
        return function_exists('esc_attr') ? esc_attr($value) : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
