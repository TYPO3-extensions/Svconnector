<?php
namespace Cobweb\Svconnector\Controller;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Cobweb\Svconnector\Domain\Repository\ConnectorRepository;
use TYPO3\CMS\Backend\View\BackendTemplateView;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Controller for the backend module
 *
 * @author Francois Suter (Cobweb) <typo3@cobweb.ch>
 * @package TYPO3
 * @subpackage tx_svconnector
 */
class TestingController extends ActionController
{
    /**
     * @var ConnectorRepository
     */
    protected $connectorRepository;

    /**
     * List of configuration samples provided by the various connector services
     * @var array
     */
    protected $sampleConfigurations = array();

    /**
     * Injects an instance of the connector repository
     *
     * @param ConnectorRepository $connectorRepository
     * @return void
     */
    public function injectConfigurationRepository(ConnectorRepository $connectorRepository)
    {
        $this->connectorRepository = $connectorRepository;
    }

    /**
     * Initializes the view before invoking an action method.
     *
     * Override this method to solve assign variables common for all actions
     * or prepare the view in another way before the action is called.
     *
     * @param ViewInterface $view The view to be initialized
     * @return void
     */
    protected function initializeView(ViewInterface $view)
    {
        // Get the sample configurations provided by the various connector services
        $this->sampleConfigurations = $this->connectorRepository->findAllSampleConfigurations();
        if ($view instanceof BackendTemplateView) {
            parent::initializeView($view);
            $template = $view->getModuleTemplate();
            $template->getPageRenderer()->addInlineSettingArray(
                    'svconnector',
                    $this->sampleConfigurations
            );
            $template->getPageRenderer()->loadRequireJsModule('TYPO3/CMS/Svconnector/TestingModule');
        }
    }

    /**
     * Initializes the template to use for all actions.
     *
     * @return void
     */
    protected function initializeAction()
    {
        $this->defaultViewObjectName = BackendTemplateView::class;
    }

    /**
     * Renders the form for testing services
     *
     * @return void
     */
    public function defaultAction()
    {
        // Check unavailable services
        // If there are any, display a warning about it
        $unavailableServices = $this->connectorRepository->findAllUnavailable();
        if (count($unavailableServices) > 0) {
            $this->addFlashMessage(
                    LocalizationUtility::translate(
                            'services.not.available',
                            'svconnector',
                            array(implode(', ', $unavailableServices))
                    ),
                    '',
                    FlashMessage::WARNING
            );
        }
        // Get available services and pass them to the view
        $availableServices = $this->connectorRepository->findAllAvailable();
        $this->view->assign('services', $availableServices);
        if (count($availableServices) === 0) {
            // If there are no available services, but some are not available, it means all installed connector
            // services are unavailable. This is a weird situation, we issue a warning.
            if (count($unavailableServices) > 0) {
                $this->addFlashMessage(
                        LocalizationUtility::translate('no.services.available', 'svconnector'),
                        '',
                        FlashMessage::WARNING
                );

                // If there are simply no services, we display a notice
            } else {
                $this->addFlashMessage(
                        LocalizationUtility::translate('no.services', 'svconnector'),
                        '',
                        FlashMessage::NOTICE
                );
            }
        }

        // Check if a request for testing was submitted
        // If yes, execute the testing and pass both arguments and result to the view
        if ($this->request->hasArgument('tx_svconnector')) {
            $arguments = $this->request->getArgument('tx_svconnector');
            // If no parameters were passed, try to fall back on sample configuration, if defined
            if (empty($arguments['parameters'])) {
                $parameters = (isset($this->sampleConfigurations[$arguments['service']])) ? $this->sampleConfigurations[$arguments['service']] : '';
            } else {
                $parameters = $arguments['parameters'];
            }
            $this->view->assignMultiple(
                    array(
                            'selectedService' => $arguments['service'],
                            'parameters' => $parameters,
                            'format' => $arguments['format'],
                            'testResult' => $this->performTest(
                                    $arguments['service'],
                                    $arguments['parameters'],
                                    $arguments['format']
                            )
                    )
            );
        } else {
            // Select the first service in the list as default and get its sample configuration, if defined
            $defaultService = key($availableServices);
            $defaultParameters = (isset($this->sampleConfigurations[$defaultService])) ? $this->sampleConfigurations[$defaultService] : '';
            $this->view->assignMultiple(
                    array(
                            'selectedService' => $defaultService,
                            'parameters' => $defaultParameters,
                            'format' => 0,
                            'testResult' => ''
                    )
            );
        }
    }

    /**
     * Performs the connection test for the selected service and passes the appropriate results to the view
     *
     * @param string $service Key of the service to test
     * @param string $parameters Parameters for the service being tested
     * @param integer $format Type of format to use (0 = raw, 1 = array, 2 = xml)
     * @return string Result from the test
     */
    protected function performTest($service, $parameters, $format)
    {
        $result = '';

        // Get the corresponding service object from the repository
        $serviceObject = $this->connectorRepository->findServiceByKey($service);
        if ($serviceObject->init()) {
            $parameters = $this->parseParameters($parameters);
            try {
                // Call the right "fetcher" depending on chosen format
                switch ($format) {
                    case 1:
                        $result = $serviceObject->fetchArray($parameters);
                        break;
                    case 2:
                        $result = $serviceObject->fetchXML($parameters);
                        break;
                    default:
                        $result = $serviceObject->fetchRaw($parameters);
                        break;
                }
                // If the result is empty, issue an information message
                if (empty($result)) {
                    $this->addFlashMessage(
                            LocalizationUtility::translate('no.result', 'svconnector'),
                            '',
                            FlashMessage::INFO
                    );
                }
            } // Catch the exception and display an error message
            catch (\Exception $e) {
                $this->addFlashMessage(
                        LocalizationUtility::translate('service.error', 'svconnector',
                                array($e->getMessage(), $e->getCode())),
                        '',
                        FlashMessage::ERROR
                );
            }
        }
        return $result;
    }

    /**
     * Parses the parameters input string and transforms it into an array of key-value pairs
     *
     * @param string $parametersString Input string from the query variables
     * @return array Array of key-value pairs
     */
    protected function parseParameters($parametersString)
    {
        $parameters = array();
        $lines = GeneralUtility::trimExplode(
                "\n",
                $parametersString,
                true
        );
        foreach ($lines as $aLine) {
            $lineParts = GeneralUtility::trimExplode(
                    '=',
                    $aLine,
                    true
            );
            $key = array_shift($lineParts);
            $value = implode('=', $lineParts);
            // Handle special case of value "tab"
            if ($value === '\t') {
                $value = "\t";
            }
            $parameters[$key] = $value;
        }
        return $parameters;
    }
}
