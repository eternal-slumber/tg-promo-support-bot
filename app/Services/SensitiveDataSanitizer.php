<?php

namespace App\Services;

use App\Data\SanitizedText;

final class SensitiveDataSanitizer
{
    public const string PaymentCard = 'payment_card';

    public const string Otp = 'otp';

    public const string Password = 'password';

    private const string PaymentCardMask = '[REDACTED_PAYMENT_CARD]';

    private const string OtpMask = '[REDACTED_OTP]';

    private const string PasswordMask = '[REDACTED_PASSWORD]';

    public function sanitize(string $text): SanitizedText
    {
        $redactionTypes = [];

        $text = preg_replace_callback(
            '~(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)~',
            function (array $matches) use (&$redactionTypes): string {
                $candidate = $matches[0];
                $digits = preg_replace('/\D/', '', $candidate) ?? '';
                $isGroupedFourByFour = preg_match('/^\d{4}(?:[ -]\d{4}){3}$/', $candidate) === 1;

                if (! $isGroupedFourByFour && ! $this->passesLuhn($digits)) {
                    return $candidate;
                }

                $redactionTypes[] = self::PaymentCard;

                return self::PaymentCardMask;
            },
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '~\b(?<label>(?:код\s+из\s+(?:смс|sms)|(?:смс|sms)\s*[- ]?код|otp|одноразовый\s+код))\s*[:=№#-]?\s*(?<secret>[a-zа-я0-9]{4,8})\b~iu',
            function (array $matches) use (&$redactionTypes): string {
                $redactionTypes[] = self::Otp;

                return str_replace($matches['secret'], self::OtpMask, $matches[0]);
            },
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '~\b(?:(?<label>пароль|password|pwd)(?<separator>\s*[:=—-]\s*)(?<secret>\S+)|(?<owned_label>мой\s+(?:пароль|password|pwd))(?<owned_separator>\s+)(?<owned_secret>\S+))~iu',
            function (array $matches) use (&$redactionTypes): string {
                $secret = $matches['secret'] !== '' ? $matches['secret'] : $matches['owned_secret'];
                $redactionTypes[] = self::Password;

                return str_replace($secret, self::PasswordMask, $matches[0]);
            },
            $text,
        ) ?? $text;

        $redactionTypes = array_values(array_unique($redactionTypes));

        return new SanitizedText($text, $redactionTypes !== [], $redactionTypes);
    }

    private function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $parity = strlen($digits) % 2;

        foreach (str_split($digits) as $index => $digit) {
            $value = (int) $digit;

            if ($index % 2 === $parity) {
                $value *= 2;

                if ($value > 9) {
                    $value -= 9;
                }
            }

            $sum += $value;
        }

        return $sum % 10 === 0;
    }
}
