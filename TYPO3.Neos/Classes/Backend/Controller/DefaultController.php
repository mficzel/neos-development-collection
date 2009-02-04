<?php
declare(ENCODING = 'utf-8');
namespace F3\TYPO3\Backend\Controller;

/*                                                                        *
 * This script belongs to the FLOW3 package "TYPO3".                      *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License as published by the Free   *
 * Software Foundation, either version 3 of the License, or (at your      *
 * option) any later version.                                             *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General      *
 * Public License for more details.                                       *
 *                                                                        *
 * You should have received a copy of the GNU General Public License      *
 * along with the script.                                                 *
 * If not, see http://www.gnu.org/licenses/gpl.html                       *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * @package TYPO3
 * @version $Id$
 */

/**
 * The TYPO3 Backend controller
 *
 * @package TYPO3
 * @version $Id$
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
class DefaultController extends \F3\FLOW3\MVC\Controller\ActionController {

	/**
	 * Only Web Requests are supported
	 * @var array
	 */
	protected $supportedRequestTypes = array('F3\FLOW3\MVC\Web\Request');

	/**
	 * @var string
	 */
	protected $viewObjectName = 'F3\Fluid\View\TemplateView';

	/**
	 * Default action of the backend controller.
	 * Forwards the request to the default module.
	 *
	 * @return string
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function indexAction() {
		$this->view->assign('baseURI', $this->request->getBaseURI());
		return $this->view->render();
	}

	/**
	 * Sets up some data for playing around ...
	 *
	 * @return string
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function setupAction() {

			// Create structure nodes
		$structureNode1 = $this->objectFactory->create('F3\TYPO3\Domain\Model\StructureNode');
		$structureNode1a = $this->objectFactory->create('F3\TYPO3\Domain\Model\StructureNode');
		$structureNode1aa = $this->objectFactory->create('F3\TYPO3\Domain\Model\StructureNode');
		$structureNode1b = $this->objectFactory->create('F3\TYPO3\Domain\Model\StructureNode');
		$structureNode1c = $this->objectFactory->create('F3\TYPO3\Domain\Model\StructureNode');
		$structureNode1d = $this->objectFactory->create('F3\TYPO3\Domain\Model\StructureNode');

		$structureNode1->addChildNode($structureNode1a);
		$structureNode1->addChildNode($structureNode1b);
		$structureNode1->addChildNode($structureNode1c);
		$structureNode1->addChildNode($structureNode1d);
		$structureNode1a->addChildNode($structureNode1aa);

			// Create pages
		$page1 = $this->objectFactory->create('F3\TYPO3\Domain\Model\Content\Page', 'Page 1');
		$structureNode1->setContent($page1);

		$page1a = $this->objectFactory->create('F3\TYPO3\Domain\Model\Content\Page', 'Page 1a');
		$structureNode1a->setContent($page1a);

		$page1aa = $this->objectFactory->create('F3\TYPO3\Domain\Model\Content\Page', 'Page 1aa');
		$structureNode1aa->setContent($page1aa);

		$page1b = $this->objectFactory->create('F3\TYPO3\Domain\Model\Content\Page', 'Page 1b');
		$structureNode1b->setContent($page1b);

			// Create text content
		$text1c = $this->objectFactory->create('F3\TYPO3\Domain\Model\Content\Text', 'Text 1c');
		$structureNode1c->setContent($text1c);

		$text1d = $this->objectFactory->create('F3\TYPO3\Domain\Model\Content\Text', 'Text 1d');
		$structureNode1d->setContent($text1d);

			// Create a sample site
		$site = $this->objectFactory->create('F3\TYPO3\Domain\Model\Site');
		$site->setName('typo3.org');
		$site->setRootStructureNode($structureNode1);

		$siteRepository = $this->objectManager->getObject('F3\TYPO3\Domain\Model\SiteRepository');
		$siteRepository->add($site);

			// Create a second sample site
		$site = $this->objectFactory->create('F3\TYPO3\Domain\Model\Site');
		$site->setName('flow3.typo3.org');
		$siteRepository->add($site);

		return 'Created some data for playing around.';
	}
}
?>