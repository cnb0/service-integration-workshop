<?php
declare(strict_types=1);

use Bunny\Message;
use function Common\CommandLine\make_red;
use function Common\CommandLine\stdout;
use JsonSchema\Validator;
use OrdersAndRegistrations\Application;
use OrdersAndRegistrations\PlaceOrder;
use Shared\RabbitMQ\Queue;
use function Common\Resilience\retry;

require __DIR__ . '/../../vendor/autoload.php';

$app = new Application();

retry(3, 1000, function () use ($app) {
    Queue::consume(
        function (Message $message) use ($app) {
            if ($message->getHeader('message_type') === 'orders_and_registrations.place_order') {
                $decodedData = json_decode($message->content);
                $validator = new Validator();
                $validator->validate($decodedData, (object)['$ref' => 'file://' . __DIR__ . '/place-order.v1.json']);

                if (!$validator->isValid()) {
                    stdout(make_red('Invalid message format. Violations:'));
                    foreach ($validator->getErrors() as $error) {
                        stdout(sprintf("[%s] %s\n", $error['property'], $error['message']));
                    }
                    return;
                }

                $command = new PlaceOrder();
                $command->orderId = $decodedData->orderId;
                $command->conferenceId = $decodedData->conferenceId;
                $command->numberOfTickets = $decodedData->numberOfTickets;

                $app->placeOrder($command);
            } elseif ($message->getHeader('message_type') === 'conference_management.conference_created') {
                $app->whenConferenceCreated(json_decode($message->content));
            }
        },
        'orders_and_registrations'
    );
});
