<?php
/**
 * Admin Controller
 *
 * PHP version 5
 *
 * Copyright (C) Moravian Library 2016.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  Controller
 * @author   Martin Kravec <martin.kravec@mzk.cz>
 * @license  http://opensource.org/licenses/gpl-3.0.php GNU General Public License
 */
namespace CPK\Controller;

use MZKCommon\Controller\ExceptionsTrait,
    CPK\Db\Row\User;

/**
 * Class controls VuFind administration.
 *
 * @category VuFind2
 * @package  Controller
 * @author   Martin Kravec <martin.kravec@mzk.cz>
 * @license  http://opensource.org/licenses/gpl-3.0.php GNU General Public License
 */
class AdminController extends \VuFind\Controller\AbstractBase
{
    use ExceptionsTrait;
    
    /**
     * Source / identifier of main portal admin
     * 
     * @var string
     */
    const PORTAL_ADMIN_SOURCE = 'cpk';
    
    /**
     * Holds names of institutions user is admin of
     * 
     * @var array
     */
    protected $institutionsBeingAdminAt = [];
    
    /**
     * Admin home.
     *
     * @return \Zend\View\Model\ViewModel
     */
    public function homeAction()
    {
        if (! ($user = $this->getLoggedInUser()) instanceof User){
            return $user; // Not logged in, returns redirection
        }
        
        $configLocator = $this->serviceLocator->get('VuFind\Config');
        
        $multibackend = $configLocator->get('MultiBackend')->toArray();
        
        $driversPath = empty($multibackend['General']['drivers_path']) ? '.' : $multibackend['General']['drivers_path'];        

        $configs = [];
        
        // Fetch all configs
        foreach ($this->institutionsBeingAdminAt as $adminSource) {
            
            // Exclude portal configs as they doesn't exist
            if (strtolower($adminSource) !== self::PORTAL_ADMIN_SOURCE) {
                
                $config = $configLocator->get($driversPath . '/' . $adminSource);
            
                $configs[$adminSource] = $config->toArray();
            }
        }

        $this->layout()->searchbox = false;
        return $this->createViewModel(['user' => $user, 'configs' => $configs]);
    }
    
    public function portalPagesAction()
    {
        // Log in first!
        if (! ($user = $this->getLoggedInUser()) instanceof User){
            return $user; // Not logged in, returns redirection
        }
        
        if (! $this->isPortalAdmin()) {
            return $this->forceLogin('You\'re not a portal admin!');
        }
        // Logged In successfull
        
        $viewModel = $this->createViewModel();
        $viewModel->setVariable('user', $user);
        
        $portalPagesTable = $this->getTable("portalpages");
        
        $positions = ['left', 'middle', 'right'];
        $placements = ['footer', 'advanced-search'];
        
        $subAction = $this->params()->fromRoute('subaction');
        if ($subAction == 'Edit') { // is edit in route?
            $pageId = (int) $this->params()->fromRoute('param');
            $page = $portalPagesTable->getPageById($pageId);
            $viewModel->setVariable('page', $page);
            
            $viewModel->setVariable('positions', $positions);
            $viewModel->setVariable('placements', $placements);
            
            $viewModel->setTemplate('admin/edit-portal-page');
        } else if ($subAction == 'Save') {
            $post = $this->params()->fromPost();
            $portalPagesTable->save($post);
            return $this->forwardTo('Admin', 'PortalPages');
        } else if ($subAction == 'Insert') {
            $post = $this->params()->fromPost();
            $portalPagesTable->insertNewPage($post);
            return $this->forwardTo('Admin', 'PortalPages');
        } else if ($subAction == 'Delete') {
            $pageId = $this->params()->fromRoute('param');
            if (! empty($pageId)) {
                $portalPagesTable->delete($pageId);
            }
            return $this->forwardTo('Admin', 'PortalPages');
        } else if ($subAction == 'Create') {
            $viewModel->setVariable('positions', $positions);
            $viewModel->setVariable('placements', $placements);
            $viewModel->setTemplate('admin/create-portal-page');
        } else { // normal view
    	    $allPages = $portalPagesTable->getAllPages('*', false);
    	    $viewModel->setVariable('pages', $allPages);
        }
        
        $this->layout()->searchbox = false;
        return $viewModel;
    }    
    
    /**
     * Permissions manager
     *
     * @return \Zend\View\Model\ViewModel
     */
    public function permissionsManagerAction()
    {
        // Log in first!
        if (! ($user = $this->getLoggedInUser()) instanceof User){
            return $user; // Not logged in, returns redirection
        }
    
        if (! $this->isPortalAdmin()) {
            return $this->forceLogin('You\'re not a portal admin!');
        }
        // Logged In successfull
        
        $viewModel = $this->createViewModel();
        $viewModel->setVariable('user', $user);
        
        $userTable = $this->getTable('user');
        
        $subAction = $this->params()->fromRoute('subaction');
        if ($subAction == 'Save') {
            $post = $this->params()->fromPost();
            $userTable->saveUserWithPermissions($post['eppn'], $post['major']);
            return $this->forwardTo('Admin', 'PermissionsManager');
        } else if ($subAction == 'RemovePermissions') {
            $eppn = $this->params()->fromRoute('param');
            $major = NULL;
            $userTable->saveUserWithPermissions($eppn, $major);
            return $this->forwardTo('Admin', 'PermissionsManager');
        } else if ( $subAction == 'AddUser') {
            $viewModel->setTemplate('admin/add-user-with-permissions');
        } else if ( $subAction == 'EditUser') {
            $eppn = $this->params()->fromRoute('param');
            $major = $this->params()->fromRoute('param2');
            
            $viewModel->setVariable('eppn', $eppn);
            $viewModel->setVariable('major', $major);
            
            $viewModel->setTemplate('admin/edit-user-with-permissions');
        } else { // normal view
            $usersWithPermissions = $userTable->getUsersWithPermissions();
            $viewModel->setVariable(
                'usersWithPermissions',
                $usersWithPermissions
            );
            $viewModel->setTemplate('admin/permissions-manager');
        }
    
        $this->layout()->searchbox = false;
        return $viewModel;
    }
    
    /**
     * Checks if logged in user is a portal admin
     * 
     * @return boolean
     */
    protected function isPortalAdmin()
    {
        foreach($this->institutionsBeingAdminAt as $adminSource)
        {
            if (strtolower($adminSource) === self::PORTAL_ADMIN_SOURCE)
                return true;
        }
        
        return false;
    }
    
    /**
     * Get logged in user
     * 
     * @return \CPK\Db\Row\User|Array
     */
    protected function getLoggedInUser()
    {
        if (! $user = $this->getAuthManager()->isLoggedIn()) {
            $this->flashExceptions($this->flashMessenger());
            return $this->forceLogin();
        }
        
        if (! empty($user->major)) {
            array_push($this->institutionsBeingAdminAt, $user->major);
        }
        
        foreach($user->getLibraryCards(true) as $libCard) {
            
            if (! empty($libCard->major)) {
                array_push($this->institutionsBeingAdminAt, $libCard->major);
            }
        }
        
        if (empty($this->institutionsBeingAdminAt)) {
            $this->flashMessenger()->addErrorMessage('You\'re not an admin!');
            $this->flashExceptions($this->flashMessenger());
            return $this->forwardTo('MyResearch', 'Home');
        }
        
        return $user;
    }
}