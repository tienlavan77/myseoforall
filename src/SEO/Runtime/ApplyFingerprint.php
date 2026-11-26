<?php
/**
 * This class provides deterministic Increment 3B snapshot fingerprints.
 * It sorts associative fields and returns a stable SHA-256 digest for stale Apply detection.
 */
declare(strict_types=1);
namespace MySeoForAll\SEO\Runtime;
final class ApplyFingerprint
{
    /**
     * This method detects stale Apply snapshots.
     * It returns a prefixed SHA-256 digest of the supplied canonical snapshot.
     */
    public function fingerprint(array $snapshot): string
    {
        $canonical = $this->canonicalize($snapshot);
        return 'sha256:' . hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return mixed */
    private function canonicalize($value)
    {
        if (is_string($value)) {
            return trim(str_replace(["\\r\\n", "\\r"], "\\n", $value));
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
