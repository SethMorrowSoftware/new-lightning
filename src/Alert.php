<?php
/**
 * One alert, described once, so every channel says the same thing.
 *
 * The engine builds an Alert; Slack and email render it their own way.
 */
declare(strict_types=1);

namespace StormWatch;

final class Alert
{
    public const KIND_WARNING = 'warning';
    public const KIND_WATCH = 'watch';
    public const KIND_UPDATE = 'update';
    public const KIND_ALL_CLEAR = 'all_clear';
    public const KIND_TEST = 'test';
    public const KIND_ERROR = 'error';

    public string $kind;
    public string $title;
    public string $summary;
    public ?float $nearestMi = null;
    public ?float $bearingDeg = null;
    public ?int $struckAt = null;
    public int $strikeCount = 0;
    public int $createdAt;

    /** @var array<string,string> extra "label => value" rows shown in the message */
    public array $details = [];

    public function __construct(string $kind, string $title, string $summary)
    {
        $this->kind = $kind;
        $this->title = $title;
        $this->summary = $summary;
        $this->createdAt = time();
    }

    public function isCritical(): bool
    {
        return $this->kind === self::KIND_WARNING || $this->kind === self::KIND_UPDATE;
    }

    /** Hex colour used for the Slack attachment bar and the email header. */
    public function colour(): string
    {
        switch ($this->kind) {
            case self::KIND_WARNING:
            case self::KIND_UPDATE:
                return '#FF4D5E';
            case self::KIND_WATCH:
                return '#FFB627';
            case self::KIND_ALL_CLEAR:
                return '#4ADE9C';
            case self::KIND_ERROR:
                return '#8A6A21';
            default:
                return '#6FE3FF';
        }
    }

    public function emoji(): string
    {
        switch ($this->kind) {
            case self::KIND_WARNING:
            case self::KIND_UPDATE:
                return '⚡';
            case self::KIND_WATCH:
                return '⚠️';
            case self::KIND_ALL_CLEAR:
                return '✅';
            case self::KIND_ERROR:
                return '🛠️';
            default:
                return '🔔';
        }
    }

    /** Venue-local time of the triggering strike, formatted for humans. */
    public function localTime(?int $timestamp = null): string
    {
        $timestamp ??= $this->struckAt ?? $this->createdAt;
        try {
            $tz = new \DateTimeZone(Settings::getString('timezone') ?: 'UTC');
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz)->format('g:i:s A T');
    }

    public function distanceText(): string
    {
        if ($this->nearestMi === null) {
            return '—';
        }
        return Geo::formatDistance($this->nearestMi, Settings::getString('units'));
    }

    public function directionText(): string
    {
        if ($this->bearingDeg === null) {
            return '—';
        }
        return sprintf('%s of the venue', Geo::compassPoint($this->bearingDeg));
    }

    /** Plain-text version used as the Slack notification preview and the email body. */
    public function plainText(): string
    {
        $lines = [$this->emoji() . ' ' . $this->title, '', $this->summary];
        foreach ($this->detailRows() as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }
        return implode("\n", $lines);
    }

    /** @return array<string,string> */
    public function detailRows(): array
    {
        $rows = [];
        if ($this->nearestMi !== null) {
            $rows['Nearest strike'] = $this->distanceText();
        }
        if ($this->bearingDeg !== null) {
            $rows['Direction'] = $this->directionText();
        }
        if ($this->struckAt !== null) {
            $rows['Detected'] = $this->localTime($this->struckAt);
        }
        if ($this->strikeCount > 0) {
            $rows['Strikes in window'] = (string) $this->strikeCount;
        }
        return array_merge($rows, $this->details);
    }
}
