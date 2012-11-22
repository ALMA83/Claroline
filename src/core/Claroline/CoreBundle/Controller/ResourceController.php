<?php

namespace Claroline\CoreBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Claroline\CoreBundle\Entity\Resource\AbstractResource;
use Claroline\CoreBundle\Entity\Resource\IconType;
use Claroline\CoreBundle\Entity\Resource\ResourceShortcut;
use Claroline\CoreBundle\Form\ResourcePropertiesType;
use Claroline\CoreBundle\Form\ResourceNameType;
use Claroline\CoreBundle\Library\Resource\Event\CreateResourceEvent;
use Claroline\CoreBundle\Library\Resource\Event\CreateFormResourceEvent;
use Claroline\CoreBundle\Library\Resource\Event\CustomActionResourceEvent;
use Claroline\CoreBundle\Library\Logger\Event\ResourceLoggerEvent;
use Claroline\CoreBundle\Library\Resource\Event\OpenResourceEvent;

class ResourceController extends Controller
{
    const THUMB_PER_PAGE = 12;

    /**
     * Renders the creation form for a given resource type.
     *
     * @param string  $resourceType
     *
     * @return Response
     */
    public function creationFormAction($resourceType)
    {
        $eventName = $this->get('claroline.resource.utilities')->normalizeEventName('create_form', $resourceType);
        $event = new CreateFormResourceEvent();
        $this->get('event_dispatcher')->dispatch($eventName, $event);

        return new Response($event->getResponseContent());
    }

    /**
     * Creates a resource
     *
     * @param string  $resourceType
     * @param integer $parentId
     *
     * @return Response
     */
    public function createAction($resourceType, $parentId)
    {
        $eventName = $this->get('claroline.resource.utilities')->normalizeEventName('create', $resourceType);
        $event = new CreateResourceEvent($resourceType);
        $this->get('event_dispatcher')->dispatch($eventName, $event);
        $response = new Response();

        if (($resource = $event->getResource()) instanceof AbstractResource) {
            $manager = $this->get('claroline.resource.manager');

            if ($resourceType === 'file') {
                $mimeType = $resource->getMimeType();
                $instance = $manager->create($resource, $parentId, $resourceType, $mimeType);
            } else {
                $instance = $manager->create($resource, $parentId, $resourceType);
            }

            $response->headers->set('Content-Type', 'application/json');
            $response->setContent($this->get('claroline.resource.converter')->ResourceToJson($instance));
        } else {
            if($event->getErrorFormContent() != null){
                $response->setContent($event->getErrorFormContent());
            } else {
                throw new \Exception('creation failed');
            }
        }

        return $response;
    }

    public function openAction($resourceId, $resourceType)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $resource = $em->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')
            ->find($resourceId);

        //If it's a link, the resource will be its target.
        $resource = $this->getResource($resource);

        $event = new OpenResourceEvent($resource);
        $eventName = $this->get('claroline.resource.utilities')->normalizeEventName('open', $resourceType);
        $this->get('event_dispatcher')->dispatch($eventName, $event);

        if (!$event->getResponse() instanceof Response) {
            throw new \Exception(
                "Open event '{$eventName}' didn't return any Response."
            );
        }

        $logEvent = new ResourceLoggerEvent($resource, 'open');
        $this->get('event_dispatcher')->dispatch('log_resource', $logEvent);

        return $event->getResponse();
    }

    /**
     * Removes a many resources from a workspace.
     *
     * @return Response
     */
    public function deleteAction()
    {
        $ids = $this->container->get('request')->query->get('ids', array());
        $em = $this->getDoctrine()->getEntityManager();

        foreach ($ids as $id) {
            $resource = $em->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')
                ->find($id);
            $this->get('claroline.resource.manager')->delete($resource);
        }

        return new Response('Resource deleted', 204);
    }

   /**
    * Displays the form allowing to rename a resource.
    *
    * @param integer $resourceId
    *
    * @return Response
    */
    public function renameFormAction($resourceId)
    {
        $resource = $this->getDoctrine()
            ->getEntityManager()
            ->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')
            ->find($resourceId);
        $form = $this->createForm(new ResourceNameType(), $resource);

        return $this->render(
            'ClarolineCoreBundle:Resource:rename_form.html.twig',
            array('form' => $form->createView())
        );
    }

    /**
    * Renames a resource.
    *
    * @param integer $resourceId
    *
    * @return Response
    */
    public function renameAction($resourceId)
    {
        $request = $this->get('request');
        $em = $this->getDoctrine()->getEntityManager();
        $resource = $em->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')
            ->find($resourceId);
        $form = $this->createForm(new ResourceNameType(), $resource);
        $form->bindRequest($request);

        if ($form->isValid()) {
            $em->persist($resource);
            $em->flush();
            $response = new Response("[\"{$resource->getName()}\"]");
            $response->headers->set('Content-Type', 'application/json');

            return $response;
        }

        return $this->render(
            'ClarolineCoreBundle:Resource:rename_form.html.twig',
            array('resourceId' => $resourceId, 'form' => $form->createView())
        );
    }

    /**
     * Display the resource properties form.
     *
     * @param integer $resourceId
     *
     * @return Response
     */
    public function propertiesFormAction($resourceId)
    {
        $resource = $this->getDoctrine()
            ->getEntityManager()
            ->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')
            ->find($resourceId);
        $form = $this->createForm(new ResourcePropertiesType(), $resource);

        return $this->render(
            'ClarolineCoreBundle:Resource:form_properties.html.twig',
            array('form' => $form->createView())
        );
    }

    /**
     * Changes the resource properties
     *
     * @param integer $resourceId
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function changePropertiesAction($resourceId)
    {
        $request = $this->get('request');
        $em = $this->get('doctrine.orm.entity_manager');
        $resource = $em->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')->find($resourceId);
        $form = $this->createForm(new ResourcePropertiesType(), $resource);
        $form->bindRequest($request);

        if ($form->isValid()){
           $data = $form->getData();
           $file = $data->getUserIcon();

            if ($file !== null) {
                $this->removeOldIcon($resource);
                $manager = $this->get('claroline.resource.icon_creator');
                $icon = $manager->createCustomIcon($file);
                $em->persist($icon);

                if (get_class($resource) == 'Claroline\CoreBundle\Entity\Resource\ResourceShortcut'){
                    $icon = $icon->getShortcutIcon();
                }

                $resource->setIcon($icon);
            }

           $resource->setName($data->getName());
           $em->persist($resource);
           $em->flush();
           $content = "{";
           if (isset($icon)) {
               $content.='"icon": "'.$icon->getRelativeUrl().'"';
           } else {
               $content.='"icon": "'.$resource->getIcon()->getRelativeUrl().'"';
           }
           $content.=', "name": "'.$resource->getName().'"';
           $content.='}';
           $response = new Response($content);
           $response->headers->set('Content-Type', 'application/json');

           return $response;
        }

        return $this->render(
            'ClarolineCoreBundle:Resource:form_properties.html.twig',
            array('resourceId' => $resourceId, 'form' => $form->createView())
        );
    }

    /**
     * Moves many resource (changes their parents).
     * This function takes an array of parameters which are the ids of the moved resources.
     *
     * @return Response
     */
    public function moveAction($newParentId)
    {
        $ids = $this->container->get('request')->query->get('ids', array());
        $em = $this->getDoctrine()->getEntityManager();
        $resourceRepo = $em->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource');
        $newParent = $resourceRepo->find($newParentId);
        $resourceManager = $this->get('claroline.resource.manager');
        $converter = $this->get('claroline.utilities.entity_converter');
        $movedResources = array();

        foreach ($ids as $id) {
            $resource = $resourceRepo->find($id);

            if ($resource != null) {
                try {
                     $movedResource = $resourceManager->move($resource, $newParent);
                     $movedResources[] = $converter->toStdClass($movedResource);
                } catch (\Gedmo\Exception\UnexpectedValueException $e) {
                     throw new \RuntimeException('Cannot move a resource into itself');
                }
            }
        }

        $response = new Response(json_encode($movedResources));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Handles any custom action (i.e. not defined in this controller) on a
     * resource of a given type.
     *
     * @param string $resourceType
     * @param string $action
     * @param integer $instanceId
     *
     * @return Response
     */
    public function customAction($resourceType, $action, $resourceId)
    {
        $eventName = $this->get('claroline.resource.utilities')->normalizeEventName($action, $resourceType);
        $resource = $this->get('doctrine.orm.entity_manager')->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')->find($resourceId);
        $event = new CustomActionResourceEvent($resource);
        $this->get('event_dispatcher')->dispatch($eventName, $event);

        if (!$event->getResponse() instanceof Response) {
            throw new \Exception(
                "Custom event '{$eventName}' didn't return any Response."
            );
        }

        $ri = $this->get('doctrine.orm.entity_manager')->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')->find($resourceId);
        $logevent = new ResourceLoggerEvent(
            $ri,
            $action
        );
        $this->get('event_dispatcher')->dispatch('log_resource', $logevent);

        return $event->getResponse();
    }

    /**
     * This function takes an array of parameters. Theses parameters are the ids of the resources which are going to be downloaded.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function exportAction()
    {
        $ids = $this->container->get('request')->query->get('ids', array());
        $file = $this->get('claroline.resource.exporter')->exportResources($ids);
        $response = new StreamedResponse();

        $response->setCallBack(function() use($file){
            readfile($file);
        });

        $response->headers->set('Content-Transfer-Encoding', 'octet-stream');
        $response->headers->set('Content-Type', 'application/force-download');
        $response->headers->set('Content-Disposition', 'attachment; filename=archive');
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Connection', 'close');

        return $response;
    }

    /**
     * Returns a json representation of the root resources of every workspace whom
     * the current user is a member.
     *
     * @return Response
     */
    public function rootsAction()
    {
        $em = $this->getDoctrine()->getEntityManager();
        $user = $this->get('security.context')->getToken()->getUser();
        $results = $em->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')->listRootsForUser($user, true);
        $content = json_encode($results);
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Returns a json representation of the root resource of a workspace.
     *
     * @param integer $workspaceId
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function rootAction($workspaceId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $workspace = $em->getRepository('Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace')->find($workspaceId);
        $root = $em->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')->findOneBy(array('parent' => null, 'workspace' => $workspace->getId()));
        $response = new Response($this->get('claroline.resource.converter')->ResourceToJson($root));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Returns a json representation of the children of a resource instance.
     *
     * @param integer $instanceId
     * @param integer $type
     *
     * @return Response
     */
    public function childrenAction($resourceId, $resourceTypeId)
    {
        if ($resourceId == 0) {
            return $this->rootsAction();
        }

        $repo = $this->get('doctrine.orm.entity_manager')->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource');
        $resource = $this->getResource($repo->find($resourceId));
        $results = $repo->listDirectChildrenResources($resource->getId(), $resourceTypeId, true);
        $content = json_encode($results);
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Returns a json representation of the parents of a resource instance.
     *
     * @param integer $instanceId
     *
     * @return Response
     */
    public function parentsAction($resourceId)
    {
        $response = new Response();
        $response->headers->set('Content-Type', 'application/json');

        if (0 == $resourceId) {
            $response->setContent('[]');
        } else {
            $repo = $this->get('doctrine.orm.entity_manager')->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource');
            $resource = $repo->find($resourceId);
            $parents = $repo->listAncestors($resource);
            $response->setContent(json_encode($parents));
        }

        return $response;
    }

    /**
     * Returns a json representation of a user instances.
     *
     * @return Response
     */
    public function userEveryInstancesAction()
    {
        $user = $this->get('security.context')->getToken()->getUser();
        $results = $this->get('doctrine.orm.entity_manager')
            ->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')
            ->listResourcesForUser($user, true);

        $content = json_encode($results);
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Returns a json representation of the resources of a defined type for the current user.
     *
     * @param integer $resourceTypeId
     * @param integer $rootId
     */
    public function resourceListAction($resourceTypeId, $rootId)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $resourceType = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceType')->find($resourceTypeId);
        $root = $em->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')->find($rootId);
        $results = $em->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')->listChildrenResourceInstances($root, $resourceType, true);
        $content = json_encode($results);
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Adds multiple resource resource to a workspace.
     *
     * @param integer $resourceDestinationId
     *
     * @return Response
     */
    public function copyAction($resourceDestinationId)
    {
        $ids = $this->container->get('request')->query->get('ids', array());
        $em = $this->getDoctrine()->getEntityManager();
        $parent = $em->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')->find($resourceDestinationId);
        $converter = $this->get('claroline.utilities.entity_converter');
        $newNodes = array();

        foreach ($ids as $id) {
            $resource = $em->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')->find($id);
            if ($resource != null) {
                $newNode = $this->get('claroline.resource.manager')->copy($resource, $parent);
                $em->flush();
                $newNodes[] = $converter->toStdClass($newNode);
            }
        }

        $response = new Response(json_encode($newNodes));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Renders the searched resource list.
     *
     * @todo Add the (icon) "mimeType" filter (see ResourceInstanceRepository)
     *
     * @return Response
     */
    public function filterAction()
    {
        $queryParameters = $this->container->get('request')->query->all();
        $allowedStringCriteria = array('name', 'dateFrom', 'dateTo');
        $allowedArrayCriteria = array('roots', 'types');
        $criteria = array();

        foreach ($queryParameters as $parameter => $value) {
            if (in_array($parameter, $allowedStringCriteria) && is_string($value)) {
                $criteria[$parameter] = $value;
            } elseif (in_array($parameter, $allowedArrayCriteria) && is_array($value)) {
                $criteria[$parameter] = $value;
            }
        }

        isset($criteria['roots']) || $criteria['roots'] = array();
        $user = $this->get('security.context')->getToken()->getUser();
        $results = $this->get('doctrine.orm.entity_manager')
            ->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')
            ->listResourcesForUserWithFilter($criteria, $user, true);
        $response = new Response(json_encode($results));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    public function createShortcutAction($newParentId)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource');
        $ids = $this->container->get('request')->query->get('ids', array());
        $parent = $repo->find($newParentId);
        $converter = $this->get('claroline.utilities.entity_converter');

        foreach($ids as $resourceId){
            $resource = $repo->find($resourceId);
            $shortcut = new ResourceShortcut();
            $shortcut->setParent($parent);

            $creator = $this->get('security.context')->getToken()->getUser();
            $shortcut->setCreator($creator);
            $shortcut->setIcon($resource->getIcon()->getShortcutIcon());
            $shortcut->setName($resource->getName());
            $shortcut->setWorkspace($parent->getWorkspace());
            $shortcut->setResourceType($resource->getResourceType());

            if (get_class($resource) !== 'Claroline\CoreBundle\Entity\Resource\ResourceShortcut'){
                 $shortcut->setResource($resource);
            } else {
                 $shortcut->setResource($resource->getResource());
            }

            $em->persist($shortcut);
            $em->flush();
            $links[] = $converter->toStdClass($shortcut);
        }

        $response = new Response(json_encode($links));
        $response->headers->set('Content-Type', 'application/json');

        return $response;

    }

    private function removeOldIcon($resource)
    {
        $icon = $resource->getIcon();

        if ($icon->getIconType()->getIconType() == IconType::CUSTOM_ICON) {
            $pathName = $this->container->getParameter('claroline.thumbnails.directory')
                . DIRECTORY_SEPARATOR.$icon->getIconLocation();
            if (file_exists($pathName)) {
                unlink($pathName);
            }
        }
    }

    private function getResource($resource)
    {

        if(get_class($resource) == 'Claroline\CoreBundle\Entity\Resource\ResourceShortcut'){
            $resource = $resource->getResource();
        }

        return $resource;
    }
}