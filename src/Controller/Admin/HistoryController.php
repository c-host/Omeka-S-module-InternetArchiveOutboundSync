<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Controller\Admin;

use InternetArchiveOutboundSync\Service\OutboundRunService;
use InternetArchiveOutboundSync\Service\PushProgressService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class HistoryController extends AbstractActionController
{
    public function browseAction()
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $runs = $services->get(OutboundRunService::class)->listRuns(100);
        $progress = $services->get(PushProgressService::class);
        $activeRunIds = array_map(static function (array $push): int {
            return (int) ($push['run_id'] ?? 0);
        }, $progress->listActivePushes());

        $statusUrl = $this->url()->fromRoute('admin/internet-archive-outbound-sync/default', [
            'controller' => 'push',
            'action' => 'status',
        ]);

        $view = new ViewModel([
            'runs' => $runs,
            'activePushes' => $progress->listActivePushes(),
            'activeRunIds' => $activeRunIds,
            'timingDefaults' => $progress->timingDefaults(),
            'statusUrl' => $statusUrl,
        ]);
        $view->setTemplate('internet-archive-outbound-sync/admin/history/browse');
        return $view;
    }

    public function showAction()
    {
        $id = (int) $this->params('id');
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $runs = $services->get(OutboundRunService::class);
        $progress = $services->get(PushProgressService::class);
        $run = $runs->getRun($id);
        if (!$run) {
            return $this->redirect()->toRoute(
                'admin/internet-archive-outbound-sync/default',
                ['controller' => 'history', 'action' => 'browse']
            );
        }

        $statusUrl = $this->url()->fromRoute('admin/internet-archive-outbound-sync/default', [
            'controller' => 'push',
            'action' => 'status',
        ]);

        $view = new ViewModel([
            'run' => $run,
            'runProgress' => $progress->getRunSnapshot($id),
            'isActive' => $progress->isRunActive($id),
            'timingDefaults' => $progress->timingDefaults(),
            'statusUrl' => $statusUrl,
        ]);
        $view->setTemplate('internet-archive-outbound-sync/admin/history/show');
        return $view;
    }
}
