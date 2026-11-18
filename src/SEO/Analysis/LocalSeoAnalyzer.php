<?php

/*
 * This analyzer evaluates normalized SEO context locally and returns stable,
 * Vietnamese-facing findings without network access or persistence. It keeps
 * deterministic checks separate from provider integrations so SEO guidance
 * remains available when AI is disabled.
 */

declare(strict_types=1);

namespace MySeoForAll\SEO\Analysis;

use MySeoForAll\SEO\Context\SeoContext;

// Defines LocalSeoAnalyzer class behavior for LocalSeoAnalyzer.php.
final class LocalSeoAnalyzer
{
    private const TITLE_MIN = 30;
    private const TITLE_MAX = 60;
    private const META_MIN = 70;
    private const META_MAX = 160;

    /**
     * Analyze one normalized object and return findings ordered by severity and check order.
     *
     * @return list<array{code:string,severity:string,message:string,field:string,evidence:mixed,suggested_fix:string}>
     */
    public function analyze(SeoContext $context): array
    {
        $findings = [];
        $title = trim((string) $context->get('title', ''));
        $meta = trim((string) $context->get('meta_description', ''));
        $content = (string) $context->get('content', '');

        if ($title === '') {
            $findings[] = $this->finding(
                'title_missing',
                'blocker',
                'Thiếu tiêu đề SEO.',
                'title',
                null
            );
        } elseif ($this->textLength($title) < self::TITLE_MIN || $this->textLength($title) > self::TITLE_MAX) {
            $findings[] = $this->finding(
                'title_length',
                'warning',
                'Độ dài tiêu đề SEO nằm ngoài khoảng khuyến nghị.',
                'title',
                $this->textLength($title)
            );
        }

        if ($meta === '') {
            $findings[] = $this->finding(
                'meta_description_missing',
                'warning',
                'Thiếu meta description.',
                'meta_description',
                null
            );
        } elseif ($this->textLength($meta) < self::META_MIN || $this->textLength($meta) > self::META_MAX) {
            $findings[] = $this->finding(
                'meta_description_length',
                'warning',
                'Độ dài meta description nằm ngoài khoảng khuyến nghị.',
                'meta_description',
                $this->textLength($meta)
            );
        }

        if (trim((string) preg_replace('/\s+/', ' ', strip_tags($content))) === '') {
            $findings[] = $this->finding(
                'content_missing',
                'blocker',
                'Không có nội dung để phân tích.',
                'content',
                null
            );
        }

        $this->appendHeadingFinding($findings, $content);
        $this->appendCanonicalFinding($findings, $context);
        $this->appendImageFindings($findings, $context);
        $this->appendInternalLinkFinding($findings, $context);
        $this->appendProductFindings($findings, $context);

        usort($findings, static function (array $left, array $right): int {
            $order = ['blocker' => 0, 'warning' => 1, 'info' => 2];
            return ($order[$left['severity']] ?? 99) <=> ($order[$right['severity']] ?? 99);
        });

        return $findings;
    }

    /**
     * Add a finding when heading markup does not provide one clear primary heading.
     *
     * @param list<array{code:string,severity:string,message:string,field:string,evidence:mixed}> $findings
     */
    private function appendHeadingFinding(array &$findings, string $content): void
    {
        preg_match_all('/<h1\b[^>]*>/i', $content, $h1);
        preg_match_all('/<h([1-6])\b[^>]*>/i', $content, $headings);

        if (count($h1[0]) !== 1) {
            $findings[] = $this->finding(
                'heading_primary_count',
                'warning',
                'Nội dung nên có đúng một thẻ H1.',
                'content',
                count($h1[0])
            );
        }

        if ($content !== '' && count($headings[0]) === 0) {
            $findings[] = $this->finding(
                'heading_structure_missing',
                'info',
                'Chưa phát hiện cấu trúc heading trong nội dung.',
                'content',
                0
            );
        }
    }

    /**
     * Add a finding when a canonical URL is absent while the context has a public URL.
     *
     * @param list<array{code:string,severity:string,message:string,field:string,evidence:mixed}> $findings
     */
    private function appendCanonicalFinding(array &$findings, SeoContext $context): void
    {
        $url = trim((string) $context->get('url', ''));
        $canonical = trim((string) $context->get('canonical', ''));

        if ($url !== '' && $canonical === '') {
            $findings[] = $this->finding(
                'canonical_missing',
                'warning',
                'Có URL công khai nhưng chưa có canonical.',
                'canonical',
                $url
            );
        }
    }

    /**
     * Add a finding for images that do not have a usable alternative description.
     *
     * @param list<array{code:string,severity:string,message:string,field:string,evidence:mixed}> $findings
     */
    private function appendImageFindings(array &$findings, SeoContext $context): void
    {
        $images = $context->get('images', []);
        if (!is_array($images)) {
            return;
        }

        $missing = 0;
        foreach ($images as $image) {
            if (is_array($image) && trim((string) ($image['alt'] ?? '')) === '') {
                $missing++;
            }
        }

        if ($missing > 0) {
            $findings[] = $this->finding(
                'image_alt_missing',
                'warning',
                'Một hoặc nhiều hình ảnh thiếu alt text.',
                'images',
                $missing
            );
        }
    }

    /**
     * Add an informational finding when no internal links were provided in the context.
     *
     * @param list<array{code:string,severity:string,message:string,field:string,evidence:mixed}> $findings
     */
    private function appendInternalLinkFinding(array &$findings, SeoContext $context): void
    {
        $links = $context->get('internal_links', []);
        if (is_array($links) && count($links) === 0) {
            $findings[] = $this->finding(
                'internal_links_missing',
                'info',
                'Chưa có liên kết nội bộ được cung cấp để phân tích.',
                'internal_links',
                0
            );
        }
    }

    /**
     * Add product data findings without inventing values or reading commerce objects.
     *
     * @param list<array{code:string,severity:string,message:string,field:string,evidence:mixed}> $findings
     */
    private function appendProductFindings(array &$findings, SeoContext $context): void
    {
        if (!in_array((string) $context->get('type', ''), ['product', 'variation'], true)) {
            return;
        }

        $missing = [];
        foreach (['sku', 'price', 'stock'] as $field) {
            $value = $context->get($field);
            if ($value === null || $value === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            $findings[] = $this->finding(
                'product_fields_missing',
                'blocker',
                'Sản phẩm còn thiếu dữ liệu thương mại cần kiểm tra.',
                'product',
                $missing
            );
        }
    }

    /** Build one serializable finding with a stable code and explicit remediation. */
    private function finding(
        string $code,
        string $severity,
        string $message,
        string $field,
        mixed $evidence,
        string $suggestedFix = ''
    ): array {
        return [
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'field' => $field,
            'evidence' => $evidence,
            'suggested_fix' => $suggestedFix !== '' ? $suggestedFix : 'Rà soát và cập nhật trường này theo dữ liệu nguồn.',
        ];
    }

    /** Count characters without requiring ext-mbstring. */
    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
