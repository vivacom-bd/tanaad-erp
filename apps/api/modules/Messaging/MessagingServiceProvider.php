<?php

declare(strict_types=1);

namespace Tanaad\Messaging;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Tanaad\Messaging\Authorization\MessagingAuthorizer;
use Tanaad\Messaging\Contracts\Messenger;
use Tanaad\Messaging\Drivers\LogMessenger;
use Tanaad\Messaging\Drivers\WhatsAppCloudMessenger;
use Tanaad\Shared\Ledger\Registry;

final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Messenger::class, function (Application $app): Messenger {
            $config = $app->make(Config::class);

            return match ((string) $config->get('tanaad.messaging.driver', 'log')) {
                'whatsapp_cloud' => new WhatsAppCloudMessenger($app->make(Http::class), (array) $config->get('tanaad.messaging.whatsapp', [])),
                default => new LogMessenger($app->make(LoggerInterface::class)),
            };
        });
    }

    public function boot(Registry $registry): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        // Queueing, delivery and failure are server-only facts: no client factory, so they cannot arrive in a sync batch.
        $registry->command(Commands\SaveRecipient::class, Handlers\SaveRecipientHandler::class, MessagingAuthorizer::class)
            ->eventType('messaging.recipient_saved', Factories\SaveRecipientFactory::class);
        $registry->command(Commands\QueueMessage::class, Handlers\QueueMessageHandler::class, MessagingAuthorizer::class);
        $registry->command(Commands\RecordDelivery::class, Handlers\RecordDeliveryHandler::class, MessagingAuthorizer::class);
        $registry->command(Commands\RecordFailure::class, Handlers\RecordFailureHandler::class, MessagingAuthorizer::class);

        $registry->projectors(Projectors\MessageProjector::class, Projectors\RecipientProjector::class);
        $registry->policies(Policies\NotifyPolicy::class);
        $registry->reactors(Reactors\DeliveryReactor::class);
    }
}
