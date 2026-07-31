<?php
declare(strict_types=1);

function formatDisplayDateTime($value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    try {
        $dateTime = new DateTimeImmutable($value);
    } catch (Exception $exception) {
        return $value;
    }

    return $dateTime->format('d.m.Y H:i');
}
