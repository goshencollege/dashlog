<?php

namespace App\Service;

/**
 * Builds and parses the deterministic object keys log batches are stored under:
 *
 *   {source-slug}/{yyyy}/{mm}/{dd}/{HH}-{MM}.log.gz
 *
 * The key alone is enough to recover which source and time window an object
 * belongs to, so a backend can be relisted and its catalog rebuilt without
 * the database.
 */
class KeyScheme
{
    private const KEY_PATTERN = '#^(?<source>[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)/(?<year>\d{4})/(?<month>\d{2})/(?<day>\d{2})/(?<hour>\d{2})-(?<minute>\d{2})\.log\.gz$#';

    public function build(string $source, \DateTimeImmutable $windowStart): string
    {
        return sprintf(
            '%s/%s.log.gz',
            $this->slugify($source),
            $windowStart->setTimezone(new \DateTimeZone('UTC'))->format('Y/m/d/H-i'),
        );
    }

    public function metaKeyFor(string $objectKey): string
    {
        if (!str_ends_with($objectKey, '.log.gz')) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a recognized log object key.', $objectKey));
        }

        return substr($objectKey, 0, -strlen('.log.gz')) . '.meta.json';
    }

    /**
     * @return array{source: string, windowStart: \DateTimeImmutable}|null
     */
    public function parse(string $objectKey): ?array
    {
        if (preg_match(self::KEY_PATTERN, $objectKey, $m) !== 1) {
            return null;
        }

        [$year, $month, $day, $hour, $minute] = array_map(
            'intval',
            [$m['year'], $m['month'], $m['day'], $m['hour'], $m['minute']],
        );

        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59) {
            return null;
        }

        $windowStart = new \DateTimeImmutable(
            sprintf('%04d-%02d-%02dT%02d:%02d:00+00:00', $year, $month, $day, $hour, $minute),
        );

        return ['source' => $m['source'], 'windowStart' => $windowStart];
    }

    private function slugify(string $source): string
    {
        $slug = strtolower(trim($source));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            throw new \InvalidArgumentException('Log source cannot be slugified to an empty string.');
        }

        return $slug;
    }
}
