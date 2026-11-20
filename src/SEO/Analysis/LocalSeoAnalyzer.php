<?php

declare(strict_types=1);

namespace MySeoForAll\SEO\Analysis;

use MySeoForAll\SEO\Context\SeoContext;

/**
 * Analyze normalized SEO context using deterministic, local-only rules.
 */
final class LocalSeoAnalyzer
{
    private const TITLE_MIN = 30;
    private const TITLE_MAX = 60;
    private const META_MIN = 70;
    private const META_MAX = 160;

    /** @var array<string,int> Stable registry order for equal-severity findings. */
    private const CODE_ORDER = [
        'title_missing' => 10, 'title_invalid' => 11, 'content_missing' => 20, 'content_invalid' => 21,
        'product_fields_missing' => 30, 'product_fields_invalid' => 31,
        'variation_relationship_missing' => 35, 'variation_relationship_invalid' => 36, 'variation_id_conflict' => 37,
        'offer_invalid' => 40, 'offer_mismatch' => 41, 'title_length' => 50,
        'meta_description_missing' => 60, 'meta_description_invalid' => 61, 'meta_description_length' => 70,
        'heading_primary_count' => 80, 'heading_hierarchy' => 81, 'heading_structure_missing' => 82,
        'canonical_missing' => 90, 'canonical_invalid' => 91, 'internal_links_missing' => 100,
        'internal_links_invalid' => 101, 'internal_links_external_only' => 102,
        'internal_link_anchor_missing' => 103, 'image_alt_missing' => 110,
    ];

    /**
     * Analyze one normalized object and return a stable, bounded finding list.
     * @return list<array{code:string,severity:string,message:string,field:string,evidence:mixed,suggested_fix:string}>
     */
    public function analyze(SeoContext $context): array
    {
        $findings = [];
        $titleValue = $context->get('title');
        $metaValue = $context->get('meta_description');
        $contentValue = $context->get('content');
        $title = $this->scalarText($titleValue);
        $meta = $this->scalarText($metaValue);
        $content = $this->scalarText($contentValue);

        if ($titleValue !== null && !is_scalar($titleValue)) {
            $findings[] = $this->finding('title_invalid', 'warning', 'Tiêu đề SEO có kiểu dữ liệu không hợp lệ.', 'title', ['reason' => 'type']);
        } elseif ($title === '') {
            $findings[] = $this->finding('title_missing', 'blocker', 'Thiếu tiêu đề SEO.', 'title', null);
        } elseif (($length = $this->textLength($title)) < self::TITLE_MIN || $length > self::TITLE_MAX) {
            $findings[] = $this->finding('title_length', 'warning', 'Độ dài tiêu đề SEO nằm ngoài khoảng khuyến nghị.', 'title', $length);
        }
        if ($metaValue !== null && !is_scalar($metaValue)) {
            $findings[] = $this->finding('meta_description_invalid', 'warning', 'Meta description có kiểu dữ liệu không hợp lệ.', 'meta_description', ['reason' => 'type']);
        } elseif ($meta === '') {
            $findings[] = $this->finding('meta_description_missing', 'warning', 'Thiếu meta description.', 'meta_description', null);
        } elseif (($length = $this->textLength($meta)) < self::META_MIN || $length > self::META_MAX) {
            $findings[] = $this->finding('meta_description_length', 'warning', 'Độ dài meta description nằm ngoài khoảng khuyến nghị.', 'meta_description', $length);
        }
        if ($contentValue !== null && !is_scalar($contentValue)) {
            $findings[] = $this->finding('content_invalid', 'warning', 'Nội dung có kiểu dữ liệu không hợp lệ.', 'content', ['reason' => 'type']);
        } elseif (trim((string) preg_replace('/\s+/', ' ', strip_tags($content))) === '') {
            $findings[] = $this->finding('content_missing', 'blocker', 'Không có nội dung để phân tích.', 'content', null);
        }

        $this->appendHeadingFinding($findings, $content);
        $this->appendCanonicalFinding($findings, $context);
        $this->appendImageFindings($findings, $context);
        $this->appendInternalLinkFinding($findings, $context);
        $this->appendProductFindings($findings, $context);

        usort($findings, static function (array $left, array $right): int {
            $severity = ['blocker' => 0, 'warning' => 1, 'info' => 2];
            $result = ($severity[$left['severity']] ?? 99) <=> ($severity[$right['severity']] ?? 99);
            if ($result !== 0) return $result;
            $result = (self::CODE_ORDER[$left['code']] ?? 999) <=> (self::CODE_ORDER[$right['code']] ?? 999);
            if ($result !== 0) return $result;
            $result = strcmp($left['field'], $right['field']);
            if ($result !== 0) return $result;
            return strcmp((string) json_encode($left['evidence'], JSON_UNESCAPED_UNICODE), (string) json_encode($right['evidence'], JSON_UNESCAPED_UNICODE));
        });
        return $findings;
    }

    /** Add findings for primary heading count and skipped levels in supplied markup. */
    private function appendHeadingFinding(array &$findings, string $content): void
    {
        $safeContent = preg_replace('/<!--.*?-->|<script\b[^>]*>.*?<\/script\s*>/is', '', $content) ?? '';
        preg_match_all('/<h1\b[^>]*>/i', $safeContent, $h1);
        preg_match_all('/<h([1-6])\b[^>]*>/i', $safeContent, $headings);
        if (count($h1[0]) !== 1) {
            $findings[] = $this->finding('heading_primary_count', 'warning', 'Nội dung nên có đúng một thẻ H1.', 'content', count($h1[0]));
        }
        $levels = array_map('intval', $headings[1] ?? []);
        for ($index = 1, $count = count($levels); $index < $count; $index++) {
            if ($levels[$index] > $levels[$index - 1] + 1) {
                $findings[] = $this->finding('heading_hierarchy', 'warning', 'Cấu trúc heading bị nhảy cấp khi đi sâu.', 'content', ['from' => $levels[$index - 1], 'to' => $levels[$index]]);
                break;
            }
        }
        if ($content !== '' && $levels === []) {
            $findings[] = $this->finding('heading_structure_missing', 'info', 'Chưa phát hiện cấu trúc heading trong nội dung.', 'content', 0);
        }
    }

    /** Check public and canonical URL syntax without fetching either URL. */
    private function appendCanonicalFinding(array &$findings, SeoContext $context): void
    {
        $url = trim($this->scalarText($context->get('url', '')));
        $canonical = trim($this->scalarText($context->get('canonical', '')));
        if ($url !== '' && !$this->isPublicUrl($url)) {
            $findings[] = $this->finding('canonical_invalid', 'warning', 'URL công khai không hợp lệ để phân tích canonical.', 'url', ['reason' => 'invalid_public_url']);
            return;
        }
        if ($url !== '' && $canonical === '') {
            $findings[] = $this->finding('canonical_missing', 'warning', 'Có URL công khai nhưng chưa có canonical.', 'canonical', ['reason' => 'missing']);
            return;
        }
        if ($canonical !== '' && !$this->isPublicUrl($canonical)) {
            $findings[] = $this->finding('canonical_invalid', 'warning', 'Canonical phải là URL HTTP(S) tuyệt đối hợp lệ.', 'canonical', ['reason' => 'invalid_canonical']);
        }
    }

    /** Count images without usable alternative text. */
    private function appendImageFindings(array &$findings, SeoContext $context): void
    {
        $images = $context->get('images', []);
        if (!is_array($images)) return;
        $missing = 0;
        foreach ($images as $image) {
            if (!is_array($image) || trim($this->scalarText($image['alt'] ?? '')) === '') $missing++;
        }
        if ($missing > 0) {
            $findings[] = $this->finding('image_alt_missing', 'warning', 'Một hoặc nhiều hình ảnh thiếu alt text.', 'images', $missing);
        }
    }

    /** Check supplied links without assuming missing base URL means internal. */
    private function appendInternalLinkFinding(array &$findings, SeoContext $context): void
    {
        $links = $context->get('internal_links', []);
        if (!is_array($links)) {
            $findings[] = $this->finding('internal_links_invalid', 'warning', 'Dữ liệu liên kết nội bộ không hợp lệ.', 'internal_links', ['reason' => 'not_list']);
            return;
        }
        if ($links === []) {
            $findings[] = $this->finding('internal_links_missing', 'info', 'Chưa có liên kết nội bộ được cung cấp để phân tích.', 'internal_links', 0);
            return;
        }
        $baseHost = $this->urlHost(trim($this->scalarText($context->get('url', ''))));
        $invalid = $external = $emptyAnchor = $internal = 0;
        foreach ($links as $link) {
            if (!is_array($link)) { $invalid++; continue; }
            $url = trim($this->scalarText($link['url'] ?? ''));
            if ($url === '' || !$this->isPublicUrl($url)) { $invalid++; continue; }
            if (trim($this->scalarText($link['anchor'] ?? '')) === '') $emptyAnchor++;
            if ($baseHost !== '' && $this->urlHost($url) === $baseHost) $internal++; else $external++;
        }
        if ($invalid > 0) $findings[] = $this->finding('internal_links_invalid', 'warning', 'Một hoặc nhiều liên kết có URL không hợp lệ.', 'internal_links', $invalid);
        if ($emptyAnchor > 0) $findings[] = $this->finding('internal_link_anchor_missing', 'info', 'Một hoặc nhiều liên kết thiếu anchor text.', 'internal_links', $emptyAnchor);
        if ($baseHost !== '' && $internal === 0 && $external > 0) {
            $findings[] = $this->finding('internal_links_external_only', 'info', 'Chỉ có liên kết ngoài; chưa xác định được liên kết nội bộ.', 'internal_links', $external);
        }
    }

    /** Check product commerce fields and variation relationship structure without mutation. */
    private function appendProductFindings(array &$findings, SeoContext $context): void
    {
        $type = $this->scalarText($context->get('type', ''));
        if (!in_array($type, ['product', 'variation'], true)) return;
        $missing = [];
        $invalid = [];
        foreach (['sku', 'price', 'stock'] as $field) {
            $value = $context->get($field);
            if ($value === null || $value === '') {
                $missing[] = $field;
                continue;
            }
            $valid = match ($field) {
                'sku' => is_string($value) && trim($value) !== '',
                'price' => $this->canonicalDecimal($value) !== null,
                'stock' => is_int($value),
                default => false,
            };
            if (!$valid) $invalid[] = $field;
        }
        if ($missing !== []) {
            $findings[] = $this->finding('product_fields_missing', 'blocker', 'Sản phẩm còn thiếu dữ liệu thương mại cần kiểm tra.', 'product', $missing);
        }
        if ($invalid !== []) {
            $findings[] = $this->finding('product_fields_invalid', 'warning', 'Dữ liệu thương mại của sản phẩm có kiểu hoặc định dạng không hợp lệ.', 'product', $invalid); 
        }
        $offer = $context->get('offer');
        if ($offer !== null) {
            if (!is_array($offer) || !array_key_exists('price', $offer) || !array_key_exists('currency', $offer) || !array_key_exists('availability', $offer)) {
                $findings[] = $this->finding('offer_invalid', 'warning', 'Offer thiếu trường bắt buộc hoặc sai kiểu dữ liệu.', 'offer', ['reason' => 'shape']);
            } elseif (!is_scalar($offer['price']) || !is_scalar($offer['currency']) || !is_scalar($offer['availability'])) {
                $findings[] = $this->finding('offer_invalid', 'warning', 'Offer có trường không thuộc kiểu scalar.', 'offer', ['reason' => 'type']);
            } elseif (($offerPrice = $this->canonicalDecimal($offer['price'])) === null) {
                $findings[] = $this->finding('offer_invalid', 'warning', 'Offer có giá decimal không hợp lệ.', 'offer', ['reason' => 'price']);
            } elseif (($productPrice = $this->canonicalDecimal($context->get('price'))) !== null && $productPrice !== $offerPrice) {
                $findings[] = $this->finding('offer_mismatch', 'warning', 'Giá offer không khớp với giá sản phẩm được cung cấp.', 'offer', ['reason' => 'price_conflict']);
            }
        }
        if ($type !== 'variation') return;
        $variation = $context->get('variation');
        if (!is_array($variation) || !array_key_exists('id', $variation) || !array_key_exists('parent_id', $variation)) {
            $findings[] = $this->finding('variation_relationship_missing', 'blocker', 'Variation thiếu quan hệ parent hợp lệ.', 'variation', ['reason' => 'missing']);
            return;
        }
        if (!is_int($variation['id']) || !is_int($variation['parent_id']) || $variation['id'] <= 0 || $variation['parent_id'] <= 0 || $variation['id'] === $variation['parent_id']) {
            $findings[] = $this->finding('variation_relationship_invalid', 'blocker', 'Quan hệ parent của variation không hợp lệ.', 'variation', ['reason' => 'invalid']);
        }
        $topLevelId = $context->get('id');
        if (is_int($topLevelId) && $topLevelId > 0 && $topLevelId !== $variation['id']) {
            $findings[] = $this->finding('variation_id_conflict', 'warning', 'ID cấp đầu không khớp với ID của variation.', 'id', ['top_level' => $topLevelId, 'variation' => $variation['id']]);
        }
    }

    /** Build one bounded finding with the stable six-field public contract. */
    private function finding(string $code, string $severity, string $message, string $field, mixed $evidence, string $suggestedFix = ''): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => $message, 'field' => $field, 'evidence' => $evidence, 'suggested_fix' => $suggestedFix !== '' ? $suggestedFix : 'Rà soát và cập nhật trường này theo dữ liệu nguồn.'];
    }

    /** Count UTF-8 code points consistently and use a deterministic fallback for invalid input. */
    private function textLength(string $value): int
    {
        if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
        $matches = [];
        if (preg_match_all('/./us', $value, $matches) !== false) return count($matches[0]);
        return strlen($value);
    }

    /** Convert normalized scalar context values to text without inventing object data. */
    private function scalarText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** Normalize a non-negative decimal string without float conversion for commerce comparison. */
    private function canonicalDecimal(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value)) return null;
        $text = trim((string) $value);
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?$/', $text)) return null;
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($fraction, '0');
        return $fraction === '' ? $whole : $whole . '.' . $fraction;
    }

    /** Validate an absolute public HTTP(S) URL without DNS or network access. */
    private function isPublicUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) return false;
        $parts = parse_url($url);
        return is_array($parts) && isset($parts['scheme'], $parts['host']) && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true) && !isset($parts['user'], $parts['pass']) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** Extract a normalized hostname for same-host link classification. */
    private function urlHost(string $url): string
    {
        $parts = parse_url($url);
        return is_array($parts) && isset($parts['host']) ? strtolower((string) $parts['host']) : '';
    }
}
