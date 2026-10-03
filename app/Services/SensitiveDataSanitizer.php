<?php

namespace App\Services;

use App\Data\SanitizedText;

/**
 * ponytail: heuristic detection of card-like numbers and labelled secrets; extend patterns for new input formats.
 * Unquoted password phrases end at a newline, comma or semicolon; quoted values may include separators.
 * Without a separator, values need quotes, a digit/password punctuation, or a single token after мой/my.
 * Plain alphabetic phrases need a separator or quotes; OTP values must contain digits.
 * Explicit/quoted values are processed before owned tokens, so quoted separators cannot split a secret.
 */
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
            [
                '~(?<!\d)(?:[0-9]{4}(?:[\h\p{Pd}\x{2212}]+[0-9]{4}){3}|[0-9]{13,19})(?!\d)~u',
                '~(?<!\d)[0-9]+(?:[\h\p{Pd}\x{2212}]+[0-9]+)*(?!\d)~u',
            ],
            function (array $matches) use (&$redactionTypes): string {
                return $this->redactCardRun($matches[0], $redactionTypes);
            },
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '~\b(?<label>(?:код\s+из\s+(?:смс|sms)|(?:смс|sms)\s*[- ]?код|otp|одноразовый\s+код))(?<separator>\s*[:=№#-]?\s*)(?<secret>[0-9](?:\h*[0-9]){3,}|(?=[a-zа-я0-9]{4,8}\b)(?=[a-zа-я0-9]*[0-9])[a-zа-я0-9]{4,8})\b~iu',
            function (array $matches) use (&$redactionTypes): string {
                $redactionTypes[] = self::Otp;

                return $matches['label'].$matches['separator'].self::OtpMask;
            },
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            [
                '~\b(?<label>(?:мой\s+)?(?:пароль|password|pwd))(?<separator>\s*[:=—-]\s*|\h+(?=["\'«]|[^\h\r\n,;]*[0-9!#@$%^&*_=+\-]))(?<secret>"[^"\r\n]+"|\'[^\'\r\n]+\'|«[^»\r\n]+»|[^\h\r\n,;][^\r\n,;]*)~iu',
                '~\b(?<label>(?:мой|my)\h+(?:пароль|password|pwd))(?<separator>\h+)(?<secret>[^:=—\-\h\r\n,;][^\h\r\n,;]*)(?=\h*(?:[,;\r\n]|$))~iu',
            ],
            function (array $matches) use (&$redactionTypes): string {
                if (mb_trim($matches['secret']) === self::PasswordMask) {
                    return $matches[0];
                }

                $redactionTypes[] = self::Password;

                return $matches['label'].$matches['separator'].self::PasswordMask;
            },
            $text,
        ) ?? $text;

        $redactionTypes = array_values(array_unique($redactionTypes));

        return new SanitizedText($text, $redactionTypes !== [], $redactionTypes);
    }

    /** @param list<string> $redactionTypes */
    private function redactCardRun(string $text, array &$redactionTypes): string
    {
        $groups = preg_split('/[\h\p{Pd}\x{2212}]+/u', $text, -1, PREG_SPLIT_OFFSET_CAPTURE);
        $result = '';
        $offset = 0;
        $groupCount = count($groups);

        for ($start = 0; $start < $groupCount; $start++) {
            $digits = '';

            for ($end = $start; $end < $groupCount; $end++) {
                $digits .= $groups[$end][0];

                if (strlen($digits) > 19) {
                    break;
                }

                if (strlen($digits) < 13) {
                    continue;
                }

                $candidateEnd = $groups[$end][1] + strlen($groups[$end][0]);
                $candidate = substr($text, $groups[$start][1], $candidateEnd - $groups[$start][1]);

                if (preg_match('/^[0-9]{4}(?:[\h\p{Pd}\x{2212}]+[0-9]{4}){3}$/u', $candidate) !== 1 && ! $this->passesLuhn($digits)) {
                    continue;
                }

                $result .= substr($text, $offset, $groups[$start][1] - $offset).self::PaymentCardMask;
                $offset = $candidateEnd;
                $redactionTypes[] = self::PaymentCard;
                $start = $end;

                break;
            }
        }

        return $result.substr($text, $offset);
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
