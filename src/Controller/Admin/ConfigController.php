<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Controller\Admin;

use InternetArchiveOutboundSync\Service\ConnectionTestService;
use Laminas\Mvc\Controller\AbstractActionController;

class ConfigController extends AbstractActionController
{
    public function testConnectionAction()
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $result = $services->get(ConnectionTestService::class)->test();
        if ($result['success']) {
            $this->messenger()->addSuccess($result['message']);
        } else {
            $this->messenger()->addError($result['message']);
        }
        return $this->redirect()->toRoute('admin/default', [
            'controller' => 'module',
            'action' => 'configure',
        ], [
            'query' => ['id' => 'InternetArchiveOutboundSync'],
        ]);
    }
}
