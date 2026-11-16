<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Drivers;

use Illuminate\Http\Client\Factory;
use RuntimeException;
use Tanaad\Messaging\Contracts\Messenger;
use Tanaad\Messaging\Support\DeliveryResult;
use Tanaad\Messaging\Support\OutboundMessage;

/** WhatsApp Cloud API (Graph): one template message per POST. Token and phone id come from the environment. */
final class WhatsAppCloudMessenger implements Messenger
{
    /** @param array{token?: string|null, phone_id?: string|null, api_version?: string, base_url?: string} $config */
    public function __construct(private readonly Factory $http, private readonly array $config) {}

    public function send(OutboundMessage $message): DeliveryResult
    {
        $token = (string) ($this->config['token'] ?? '');
        $phoneId = (string) ($this->config['phone_id'] ?? '');
        if ($token === '' || $phoneId === '') {
            throw new RuntimeException('WhatsApp is not configured (token or phone id missing).');
        }
        if ($message->template === null || $message->template === '') {
            throw new RuntimeException('No WhatsApp template is mapped for this message.');
        }
        $url = rtrim((string) ($this->config['base_url'] ?? 'https://graph.facebook.com'), '/').'/'.($this->config['api_version'] ?? 'v21.0')."/{$phoneId}/messages";
        $components = $message->params === [] ? [] : [[
            'type' => 'body',
            'parameters' => array_map(fn (string $p): array => ['type' => 'text', 'text' => $p], $message->params),
        ]];

        $response = $this->http->withToken($token)->acceptJson()->asJson()->timeout(15)->post($url, [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($message->to, '+'),
            'type' => 'template',
            'template' => ['name' => $message->template, 'language' => ['code' => $message->language ?? 'en'], 'components' => $components],
        ]);
        if (! $response->successful()) {
            $error = $response->json('error.message');
            throw new RuntimeException('WhatsApp rejected the message (HTTP '.$response->status().')'.(is_string($error) ? ': '.mb_substr($error, 0, 200) : ''));
        }
        $providerId = $response->json('messages.0.id');

        return new DeliveryResult('delivered', 'whatsapp_cloud', is_string($providerId) ? $providerId : null);
    }
}
