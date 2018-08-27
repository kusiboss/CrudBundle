<?php
/*
 * Copyright (c) 2016, whatwedo GmbH
 * All rights reserved
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
 * INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT
 * NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR
 * PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY,
 * WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace whatwedo\CrudBundle\Controller;

use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\RouterInterface;
use whatwedo\CrudBundle\Content\EditableContentInterface;
use whatwedo\CrudBundle\Definition\DefinitionInterface;
use whatwedo\CrudBundle\Encoder\CsvEncoder;
use whatwedo\CrudBundle\Enum\RouteEnum;
use whatwedo\CrudBundle\Event\CrudEvent;
use whatwedo\CrudBundle\Manager\DefinitionManager;
use whatwedo\CrudBundle\Normalizer\ObjectNormalizer;
use whatwedo\TableBundle\Factory\TableFactory;
use whatwedo\TableBundle\Table\ActionColumn;
use whatwedo\TableBundle\Table\DoctrineTable;
use Symfony\Component\Serializer\Serializer;


/**
 * @author Ueli Banholzer <ueli@whatwedo.ch>
 */
class CrudController extends AbstractController implements CrudDefinitionController
{
    protected $twigParametersIndex = [];
    protected $twigParametersShow = [];
    protected $twigParametersEdit = [];

    /**
     * @var DefinitionInterface
     */
    protected $definition;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var EngineInterface
     */
    protected $templating;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var RouterInterface
     */
    protected  $router;
    /**
     * @var DefinitionManager
     */
    private $definitionManager;

    /**
     * CrudController constructor.
     * @param EngineInterface $templating
     * @param LoggerInterface $logger
     */
    public function __construct(EngineInterface $templating, LoggerInterface $logger, EventDispatcherInterface $eventDispatcher, RouterInterface $router, DefinitionManager $definitionManager)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->router = $router;
        $this->templating = $templating;
        $this->logger = $logger;
        $this->definitionManager = $definitionManager;
    }


    public function configureDefinition(DefinitionInterface $definition)
    {
        $this->definition = $definition;
    }

    /**
     * @return DefinitionInterface
     */
    protected function getDefinition()
    {
        return $this->definition;
    }

    /**
     * @return Response
     */
    public function indexAction(TableFactory $tableFactory)
    {
        $this->denyAccessUnlessGranted(RouteEnum::INDEX, $this->getDefinition());

        $table = $tableFactory
            ->createDoctrineTable('index', [
                'query_builder' => $this->getDefinition()->getQueryBuilder()
            ]);

        $this->configureTable($table);
        $this->definition->overrideTableConfiguration($table);

        $this->definition->buildBreadcrumbs(null, RouteEnum::INDEX);

        return $this->render($this->getView('index.html.twig'), [
            'view' => $this->getDefinition()->createView(),
            'table' => $table,
            'title' => $this->getDefinition()->getTitle(null, RouteEnum::INDEX),
            'voter_entity' => $this->getDefinition(),
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function showAction(Request $request)
    {
        $entity = $this->getEntityOr404($request);
        $this->denyAccessUnlessGranted(RouteEnum::SHOW, $entity);
        $this->dispatchEvent(CrudEvent::PRE_SHOW_PREFIX, $entity);

        $this->definition->buildBreadcrumbs($entity, RouteEnum::SHOW);

        return $this->render($this->getView('show.html.twig'), $this->getShowParameters($entity, [
            'view' => $this->getDefinition()->createView($entity),
            'title' => $this->getDefinition()->getTitle($entity, RouteEnum::SHOW),
        ]));
    }

    protected function getShowParameters($entity, $parameters = [])
    {
        return $parameters;
    }

    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function editAction(Request $request)
    {
        $entity = $this->getEntityOr404($request);
        $this->denyAccessUnlessGranted(RouteEnum::EDIT, $entity);
        $view = $this->getDefinition()->createView($entity);

        $form = $view->getEditForm();

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $this->dispatchEvent(CrudEvent::PRE_EDIT_PREFIX, $entity);
                $this->getDoctrine()->getManager()->flush();
                $this->dispatchEvent(CrudEvent::POST_EDIT_PREFIX, $entity);

                $this->addFlash('success', sprintf('Erfolgreich gespeichert.'));

                return $this->redirectToRoute($this->getDefinition()->getRoutePrefix() . '_show', [
                    'id' => $entity->getId(),
                ]);
            } else {
                $this->addFlash('danger', sprintf('Beim Speichern ist ein Fehler aufgetreten. Bitte überprüfe deine Eingaben.'));
            }
        }

        $this->definition->buildBreadcrumbs($entity, RouteEnum::EDIT);

        return $this->render($this->getView('edit.html.twig'), $this->getEditParameters($entity, [
            'view' => $view,
            'title' => $this->getDefinition()->getTitle($entity, RouteEnum::EDIT),
        ]));
    }

    protected function getEditParameters($entity, $parameters = [])
    {
        return $parameters;
    }

    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function createAction(Request $request)
    {
        $entityName = $this->getDefinition()->getEntity();
        $entity = new $entityName;
        $this->denyAccessUnlessGranted(RouteEnum::CREATE, $entity);

        $view = $this->getDefinition()->createView($entity);
        $uri = null;

        if ($request->isMethod('get') || $request->isMethod('post')) {

            // set preselected entities
            $propertyAccessor = PropertyAccess::createPropertyAccessor();

            foreach ($view->getBlocks() as $block) {
                foreach ($block->getContents() as $content) {
                    if ($content instanceof EditableContentInterface
                        && $content->getPreselectDefinition()) {
                        $queryParameter = call_user_func([$content->getPreselectDefinition(), 'getChildRouteAddition']);

                        if ($queryParameter
                            && $request->query->has($queryParameter)) {
                            $value = $this->getDoctrine()
                                ->getRepository(call_user_func([$content->getPreselectDefinition(), 'getEntity']))
                                ->find($request->query->getInt($queryParameter));

                            if (!is_null($value)) {
                                $uri = $this->router->generate(
                                    sprintf(
                                        '%s_%s',
                                        call_user_func([$content->getPreselectDefinition(), 'getRoutePrefix']),
                                        RouteEnum::SHOW),
                                    ['id' => $value->getId()]);
                            }
                            if (!$propertyAccessor->getValue($entity, $content->getOption('accessor_path'))
                                && $request->isMethod('get')) {
                                $propertyAccessor->setValue($entity, $content->getOption('accessor_path'), $value);
                            }
                        }
                    }
                }
            }
        }

        $this->dispatchEvent(CrudEvent::CREATE_SHOW_PREFIX, $entity);

        $form = $view->getCreateForm();

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->dispatchEvent(CrudEvent::PRE_VALIDATE_PREFIX, $entity);
            if ($form->isValid()) {
                $this->dispatchEvent(CrudEvent::POST_VALIDATE_PREFIX, $entity);
                $this->dispatchEvent(CrudEvent::PRE_CREATE_PREFIX, $entity);
                $this->getDoctrine()->getManager()->persist($entity);
                $this->getDoctrine()->getManager()->flush();
                $this->dispatchEvent(CrudEvent::POST_CREATE_PREFIX, $entity);


                if (is_null($uri)) {
                    $this->addFlash('success', sprintf('Erfolgreich gespeichert.'));
                    return $this->redirectToRoute($this->getDefinition()->getRoutePrefix() . '_show', [
                        'id' => $entity->getId(),
                    ]);
                } else {
                    $this->addFlash('success', sprintf('Erfolgreich hinzugefügt.'));
                    return $this->redirect($uri);
                }
            } else {
                $this->addFlash('danger', sprintf('Beim Speichern ist ein Fehler aufgetreten. Bitte überprüfe deine Eingaben.'));
            }
        }

        $this->definition->buildBreadcrumbs(null, RouteEnum::CREATE);

        return $this->render($this->getView('create.html.twig'), $this->getCreateParameters([
            'view' => $view,
            'title' => $this->getDefinition()->getTitle(null, RouteEnum::CREATE),
        ]));
    }

    protected function getCreateParameters($parameters = [])
    {
        return $parameters;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function deleteAction(Request $request)
    {
        $entity = $this->getEntityOr404($request);
        $this->denyAccessUnlessGranted(RouteEnum::DELETE, $entity);
        $redirect = $this->getDefinition()->getDeleteRedirect($this->router, $entity);

        try {
            $this->getDoctrine()->getManager()->remove($entity);
            $this->getDoctrine()->getManager()->flush($entity);
            $this->addFlash('success', sprintf('Eintrag erfolgreich gelöscht.'));
        } catch (\Exception $e) {
            $this->addFlash('error', sprintf('Eintrag konnte nicht gelöscht werden: ' . $e->getMessage()));
            $this->logger->warning('Error while deleting: ' . $e->getMessage(), [
                'entity' => get_class($entity),
                'id' => $entity->getId(),
            ]);
        }

        return $redirect;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function exportAction(Request $request)
    {
        $entityName = $this->getDefinition()->getEntity();
        $entityReflector = new ReflectionClass($entityName);
        if ($entityReflector->isAbstract()) {
            $voterEntity = null;
        } else {
            $voterEntity = $entityReflector->newInstanceWithoutConstructor();
            $this->denyAccessUnlessGranted(RouteEnum::EXPORT, $voterEntity);
        }

        $entities = $this->getEntities($request);
        if (!isset($entities[0])) {
            $this->addFlash('warning', 'Nichts zu exportieren');
            return $this->redirectToRoute($this->getDefinition()->getRoutePrefix() . '_' . RouteEnum::INDEX);
        }

        $objectNormalizer = new ObjectNormalizer($this->definition);
        $objectNormalizer->setCustomCallbacks($this->definition->getExportCallbacks());
        $objectNormalizer->setCircularReferenceHandler(function ($obj) {
            return $obj->__toString();
        });
        $exportOptions = $this->definition->getExportOptions()['csv'];
        $csvEncoder = new CsvEncoder($exportOptions['delimiter'], $exportOptions['enclosure'], $exportOptions['escapeChar'], $exportOptions['keySeparator']);
        /** @var Serializer $serializer */
        $serializer = new Serializer([$objectNormalizer], [$csvEncoder->setHeaderTransformation($this->definition->getExportHeaders())]);
        $normalized = $serializer->normalize($entities);
        $csv = $serializer->encode($normalized, 'csv');
        $csv = static::convertToWindowsCharset($csv);
        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="export.csv"');
        return $response;
    }

    public static function convertToWindowsCharset($string) {
        $charset =  mb_detect_encoding(
            $string,
            "UTF-8, ISO-8859-1, ISO-8859-15",
            true
        );

        $string =  mb_convert_encoding($string, "Windows-1252", $charset);
        return $string;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function ajaxAction(Request $request)
    {
        $entityName = $this->getDefinition()->getEntity();
        $entityReflector = new ReflectionClass($entityName);
        if ($entityReflector->isAbstract()) {
            $voterEntity = null;
        } else {
            $voterEntity = $entityReflector->newInstanceWithoutConstructor();
            $this->denyAccessUnlessGranted(RouteEnum::AJAX, $voterEntity);
        }

        $data = [];
        foreach ($request->request->get('data') as $pair) {
            $data[$pair['key']] = $pair['value'];
        }
        $obj = $this->definition->ajaxOnDataChanged($data);
        $response = new Response(json_encode($obj));
        $response->headers->set('Content-Type', 'text/json');
        return $response;
    }

    /**
     * get specific view for a definition
     *
     * @param string $file file name
     * @return string
     */
    public function getView($file)
    {
        if ($this->templating->exists($this->getDefinition()->getTemplateDirectory() . '/' . $file)) {
            return $this->getDefinition()->getTemplateDirectory() . '/' . $file;
        }

        // fallback if it does not exist
        return '@whatwedoCrud/Crud/' . $file;
    }

    public function getActionColumnItems($row) {
        $targetDefinition = $this->definitionManager->getDefinitionFor($row);

        $actionColumnItems = [];

        if ($targetDefinition->hasCapability(RouteEnum::SHOW)) {
            $actionColumnItems[] = [
                'label' => 'Details',
                'icon' => 'arrow-right',
                'button' => 'primary',
                'route' => sprintf('%s_%s', $targetDefinition::getRoutePrefix(), RouteEnum::SHOW),
                'route_parameters' => [],
                'voter_attribute' => RouteEnum::SHOW,
            ];
        }

        if ($targetDefinition->hasCapability(RouteEnum::EDIT)) {
            $actionColumnItems[] = [
                'label' => 'Bearbeiten',
                'icon' => 'pencil',
                'button' => 'warning',
                'route' => sprintf('%s_%s', $targetDefinition::getRoutePrefix(), RouteEnum::SHOW),
                'route_parameters' => [],
                'voter_attribute' => RouteEnum::EDIT,
            ];
        }

        return $actionColumnItems;
    }

    /**
     * configures list table
     *
     * @param DoctrineTable $table
     */
    protected function configureTable(DoctrineTable $table)
    {
        $this->getDefinition()->configureTable($table);

        if ($this->getDefinition()->hasCapability(RouteEnum::SHOW)) {
            // TODO: support dynamic show route per row
            $table->setShowRoute(sprintf('%s_%s', $this->getDefinition()->getRoutePrefix(), RouteEnum::SHOW));
        }

        if ($this->getDefinition()->hasCapability(RouteEnum::EXPORT)) {
            // TODO: support dynamic export route per row
            $table->setExportRoute(sprintf('%s_%s', $this->getDefinition()->getRoutePrefix(), RouteEnum::EXPORT));
        }

        // this is normally the main table of the page, so we're fixing the header
        $table->setOption('table_attr', [
            'data-fixed-header' => 'data-fixed-header'
        ]);

        $table->addColumn('actions', ActionColumn::class, [
            'items' => [$this, 'getActionColumnItems'],
        ]);
    }

    /**
     * returns the required entity
     *
     * @param Request $request
     * @return object
     * @throws NotFoundHttpException
     */
    protected function getEntityOr404(Request $request)
    {
        $qb = $this->getDefinition()->getQueryBuilder();

        $idColumn = $qb->getEntityManager()->getClassMetadata($this->getDefinition()::getEntity())->identifier[0];

        try {
            return $qb
                ->andWhere($this->getDefinition()::getQueryAlias().'.'.$idColumn . ' = :id')
                ->setParameter('id', $request->attributes->getInt('id'))
                ->getQuery()
                ->getSingleResult();
        } catch (NoResultException|NonUniqueResultException $e) {
            throw new NotFoundHttpException(sprintf(
                'Der gewünschte Datensatz existiert in %s nicht.',
                $this->getDefinition()->getEntity()
            ));
        }
    }

    protected function getEntities(Request $request)
    {
        $ids = $request->query->get('ids', []);
        if (count($ids) > 0) {
            if ($ids[0] == -1) {
                return $this->definition->getQueryBuilder()->getQuery()->getResult();
            } else {
                return $this->definition->getQueryBuilder()
                    ->andWhere($this->definition::getQueryAlias().'.id in (:ids)')
                    ->setParameter('ids', $ids)
                    ->getQuery()->getResult();
            }
        } else {
            return [];
        }
    }

    public function dispatchEvent($event, $entity)
    {
        $this->eventDispatcher->dispatch(
            $event,
            new CrudEvent($entity)
        );

        $this->eventDispatcher->dispatch(
            $event . '.' . $this->getDefinition()->getAlias(),
            new CrudEvent($entity)
        );
    }
}
