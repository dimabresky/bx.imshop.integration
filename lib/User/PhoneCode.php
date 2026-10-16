<?php

namespace Bx\Imshop\Integration\User;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;
use Bitrix\Main\Sms\Event as SmsEvent;
use Bx\Imshop\Integration\Config;
use CUser;

/**
 * SMS code for an already identified user. Does not register or change the login phone.
 */
final class PhoneCode
{
    private const SMS_LOGIN = 'SMS_USER_AUTH_CODE';

    private const MAX_ATTEMPTS = 3;

    private const TTL = 600;

    private const CACHE_DIR = '/bx.imshop.integration/phone-code';

    private const REPLY_TEXT = 'Выслать код повторно';

    public function __construct(
        private readonly UserAccount $accounts = new UserAccount(),
        private readonly PhoneUserFinder $phones = new PhoneUserFinder(),
        private readonly UserProfile $profile = new UserProfile(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function request(array $payload): array
    {
        if (!Loader::includeModule('messageservice')) {
            return $this->error('Не удалось отправить код');
        }

        $resolved = $this->accounts->resolve($payload);
        if (!($resolved['ok'] ?? false)) {
            return $resolved['error'];
        }

        $phone = $this->phones->normalize($this->text($payload['phone'] ?? null));
        if ($phone === '') {
            return $this->error(
                trim($this->text($payload['phone'] ?? null)) === ''
                    ? 'Не указан номер телефона'
                    : 'Некорректный номер телефона'
            );
        }

        if (!$this->phones->lock($phone)) {
            return $this->error('Не удалось отправить код');
        }

        try {
            return $this->send($resolved['userId'], $phone);
        } finally {
            $this->phones->unlock($phone);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{success: bool, error?: string}
     */
    public function confirm(array $payload): array
    {
        $resolved = $this->accounts->resolve($payload);
        if (!($resolved['ok'] ?? false)) {
            return $this->failure((string) ($resolved['error']['error']['message'] ?? 'Неверный код'));
        }

        $phone = $this->phones->normalize($this->text($payload['phone'] ?? null));
        if ($phone === '') {
            return $this->failure(
                trim($this->text($payload['phone'] ?? null)) === ''
                    ? 'Не указан номер телефона'
                    : 'Некорректный номер телефона'
            );
        }

        $code = $this->text($payload['code'] ?? null);
        if ($code === '' || preg_match('/^\d+$/', $code) !== 1) {
            return $this->failure('Неверный код');
        }

        if (!$this->phones->lock($phone)) {
            return $this->failure('Не удалось подтвердить код');
        }

        try {
            return $this->check($resolved['userId'], $phone, $code);
        } finally {
            $this->phones->unlock($phone);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function send(int $userId, string $phone): array
    {
        $interval = (int) CUser::PHONE_CODE_RESEND_INTERVAL;
        if ($interval <= 0) {
            $interval = 60;
        }

        $stored = $this->read($userId, $phone);
        $now = time();
        if (is_array($stored)) {
            $elapsed = $now - (int) ($stored['sentAt'] ?? 0);
            $attempts = (int) ($stored['attempts'] ?? 0);
            if ($elapsed >= 0 && $elapsed < $interval) {
                if ($attempts >= self::MAX_ATTEMPTS) {
                    return $this->error('Превышено количество попыток входа');
                }

                return $this->otpPayload($phone, max(1, $interval - $elapsed), $attempts);
            }
        }

        $length = $this->profile->codeLength();
        if ($length < 1) {
            $length = 4;
        }
        $span = 10 ** $length;
        if (!is_int($span) || $span <= 1) {
            return $this->error('Не удалось отправить код');
        }

        $code = str_pad((string) random_int(0, $span - 1), $length, '0', STR_PAD_LEFT);
        // shortcut: код в кэше одного сервера, пропадает при сбросе кэша; таблица — если появятся несколько app-нод.
        if (!$this->write($userId, $phone, [
            'hash' => hash('sha256', $code),
            'sentAt' => $now,
            'attempts' => 0,
        ])) {
            return $this->error('Не удалось отправить код');
        }

        $sms = new SmsEvent(self::SMS_LOGIN, [
            'USER_PHONE' => $phone,
            'CODE' => $code,
        ]);
        $sms->setSite(Config::getSiteId());
        if (!$sms->send(true)->isSuccess()) {
            $this->forget($userId, $phone);

            return $this->error('Не удалось отправить код');
        }

        return $this->otpPayload($phone, $interval, 0);
    }

    /**
     * @return array{success: bool, error?: string}
     */
    private function check(int $userId, string $phone, string $code): array
    {
        $stored = $this->read($userId, $phone);
        if (!is_array($stored)) {
            return $this->failure('Неверный код');
        }
        $hash = (string) ($stored['hash'] ?? '');
        if ($hash === '') {
            return $this->failure('Неверный код');
        }

        $attempts = (int) ($stored['attempts'] ?? 0);
        if ($attempts >= self::MAX_ATTEMPTS) {
            return $this->failure('Превышено количество попыток входа');
        }

        if (!hash_equals($hash, hash('sha256', $code))) {
            $attempts++;
            $stored['attempts'] = $attempts;
            $this->write($userId, $phone, $stored);

            return $this->failure(
                $attempts >= self::MAX_ATTEMPTS
                    ? 'Превышено количество попыток входа'
                    : 'Неверный код'
            );
        }

        $this->forget($userId, $phone);

        return ['success' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function otpPayload(string $phone, int $timeout, int $attempts): array
    {
        return [
            'otp' => [
                'timeout' => $timeout,
                'attemptsLeft' => max(0, self::MAX_ATTEMPTS - $attempts),
                'message' => 'На номер ' . $this->profile->maskPhone($phone) . ' отправлено сообщение с кодом',
                'replyText' => self::REPLY_TEXT,
                'codeLength' => $this->profile->codeLength(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(int $userId, string $phone): ?array
    {
        $cache = Cache::createInstance();
        if (!$cache->initCache(self::TTL, $this->cacheId($userId, $phone), self::CACHE_DIR)) {
            return null;
        }

        $vars = $cache->getVars();

        return is_array($vars) ? $vars : null;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function write(int $userId, string $phone, array $record): bool
    {
        $cache = Cache::createInstance();
        $cacheId = $this->cacheId($userId, $phone);
        $cache->clean($cacheId, self::CACHE_DIR);
        if (!$cache->startDataCache(self::TTL, $cacheId, self::CACHE_DIR)) {
            return false;
        }

        $cache->endDataCache($record);

        return true;
    }

    private function forget(int $userId, string $phone): void
    {
        Cache::createInstance()->clean($this->cacheId($userId, $phone), self::CACHE_DIR);
    }

    private function cacheId(int $userId, string $phone): string
    {
        return $userId . ':' . $this->phones->digits($phone);
    }

    /**
     * @return array{error: array{message: string}}
     */
    private function error(string $message): array
    {
        return [
            'error' => [
                'message' => $message,
            ],
        ];
    }

    /**
     * @return array{success: false, error: string}
     */
    private function failure(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
        ];
    }

    private function text(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }

        return '';
    }
}
