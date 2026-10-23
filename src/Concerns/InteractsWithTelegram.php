<?php

namespace LaraGram\Installer\Console\Concerns;

use Throwable;

trait InteractsWithTelegram
{
    /**
     * The bot resolved from the given token, if it could be verified.
     *
     * @var array{id: int, username: string}|null
     */
    protected ?array $bot = null;

    /**
     * Determine if the given value looks like a Telegram bot token.
     */
    protected function isValidBotToken(string $token): bool
    {
        return preg_match('/^\d+:[\w-]{30,}$/', $token) === 1;
    }

    /**
     * Verify the given bot token against the Bot API and remember the bot.
     *
     * Returns an error message when Telegram rejects the token. When Telegram
     * can not be reached the token is accepted as-is, so the installation
     * still works on networks where the Bot API is unavailable.
     */
    protected function verifyBotToken(string $token): ?string
    {
        $this->bot = null;

        if (! $this->isValidBotToken($token)) {
            return 'The bot token format is invalid. You may get one from @BotFather.';
        }

        $response = $this->callBotApi($token, 'getMe');

        if ($response === null) {
            return null;
        }

        if (! ($response['ok'] ?? false)) {
            return 'Telegram rejected the bot token: '.($response['description'] ?? 'Unauthorized').'.';
        }

        $this->bot = [
            'id' => $response['result']['id'],
            'username' => $response['result']['username'] ?? '',
        ];

        return null;
    }

    /**
     * Call the given Bot API method, returning null when Telegram can not be reached.
     */
    protected function callBotApi(string $token, string $method): ?array
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.telegram.org/bot{$token}/{$method}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['User-Agent: LaraGram Installer'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);

        try {
            $response = curl_exec($curl);
        } catch (Throwable $e) {
            return null;
        }

        if (! is_string($response)) {
            return null;
        }

        $data = json_decode($response, true);

        return is_array($data) && array_key_exists('ok', $data) ? $data : null;
    }

    /**
     * Determine if the given value is a valid webhook URL.
     */
    protected function isValidWebhookUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && str_starts_with(strtolower($url), 'https://');
    }
}
