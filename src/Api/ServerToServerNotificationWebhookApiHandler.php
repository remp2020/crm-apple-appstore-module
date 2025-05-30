<?php

namespace Crm\AppleAppstoreModule\Api;

use Crm\ApiModule\Models\Api\ApiHandler;
use Crm\ApiModule\Models\Api\JsonValidationTrait;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Nette\Http\Response;
use Nette\Utils\DateTime;
use Tomaj\Hermes\Emitter;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tracy\Debugger;

class ServerToServerNotificationWebhookApiHandler extends ApiHandler
{
    use JsonValidationTrait;

    private $hermesEmitter;

    public function __construct(Emitter $hermesEmitter)
    {
        $this->hermesEmitter = $hermesEmitter;
    }

    public function params(): array
    {
        return [];
    }

    public function handle(array $params): ResponseInterface
    {
        $request = $this->rawPayload();
        $validation = $this->validateInput(__DIR__ . '/server-to-server-notification.schema.json', $request);
        if ($validation->hasErrorResponse()) {
            $errorResponse = $validation->getErrorResponse();
            $errorPayload = $errorResponse->getPayload();
            if (isset($errorPayload['errors'])) {
                $details = 'Errors: [' . print_r($errorPayload['errors'], true) . ']';
            } else {
                $details = 'Request: [' . print_r($request, true) . ']';
            }
            Debugger::log(sprintf(
                "Unable to parse JSON of Apple's ServerToServerNotification: %s. %s",
                $errorPayload['message'],
                $details,
            ), Debugger::ERROR);
            return $validation->getErrorResponse();
        }
        $parsedNotification = $validation->getParsedObject();

        $executeAt = (float) (new DateTime('now + 1 minutes'))->getTimestamp();
        $this->hermesEmitter->emit(new HermesMessage('apple-server-to-server-notification', [
            'notification' => $parsedNotification,
        ], null, null, $executeAt), HermesMessage::PRIORITY_HIGH);

        $response = new JsonApiResponse(Response::S200_OK, [
            'status' => 'ok',
            'result' => 'Server-To-Server Notification acknowledged.',
        ]);
        return $response;
    }
}
