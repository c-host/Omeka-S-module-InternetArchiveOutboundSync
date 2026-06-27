<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Listener;

use InternetArchiveOutboundSync\Service\IaIdentifierParser;
use InternetArchiveOutboundSync\Service\OutboundQueueService;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\ListenerAggregateInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;

class ContributeValidatedListener implements ListenerAggregateInterface
{
    /** @var array<int, callable> */
    protected $listeners = [];

    protected ServiceLocatorInterface $services;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
    }

    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        if (!class_exists(\Contribute\Api\Adapter\ContributionAdapter::class)) {
            return;
        }
        $this->listeners[] = $events->getSharedManager()->attach(
            \Contribute\Api\Adapter\ContributionAdapter::class,
            'api.update.post',
            [$this, 'onContributionUpdatePost'],
            $priority
        );
    }

    public function detach(EventManagerInterface $events): void
    {
        foreach ($this->listeners as $index => $listener) {
            if ($events->detach($listener)) {
                unset($this->listeners[$index]);
            }
        }
    }

    public function onContributionUpdatePost(Event $event): void
    {
        $response = $event->getParam('response');
        if (!$response || !method_exists($response, 'getContent')) {
            return;
        }

        $representation = $response->getContent();
        if (!$representation || !method_exists($representation, 'isValidated')) {
            return;
        }

        $validated = $representation->isValidated();
        $undertaken = $representation->isUndertaken();
        $queue = $this->services->get(OutboundQueueService::class);
        $contributionId = (int) $representation->id();

        if ($validated !== true || !$undertaken) {
            $queue->cancelMetadataRevisionForContribution($contributionId);
            return;
        }

        $resource = $representation->resource();
        if (!$resource) {
            return;
        }

        $itemId = (int) $resource->id();
        if ($itemId <= 0) {
            return;
        }

        $api = $this->services->get('Omeka\ApiManager');
        $item = $api->read('items', $itemId)->getContent();
        $idParser = $this->services->get(IaIdentifierParser::class);

        if (!$idParser->fromItem($item)) {
            return;
        }

        $queue->enqueueMetadataRevision($itemId, $contributionId);
    }
}
