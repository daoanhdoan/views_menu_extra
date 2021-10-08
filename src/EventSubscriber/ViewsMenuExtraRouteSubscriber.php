<?php

namespace Drupal\views_menu_extra\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\views\Plugin\views\display\DisplayRouterInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Symfony\Component\Routing\RouteCollection;

/**
 * Builds up the routes of all views.
 *
 * The general idea is to execute first all alter hooks to determine which
 * routes are overridden by views. This information is used to determine which
 * views have to be added by views in the dynamic event.
 *
 *
 * @see \Drupal\views\Plugin\views\display\PathPluginBase
 */
class ViewsMenuExtraRouteSubscriber extends RouteSubscriberBase {

  /**
   * Stores a list of view,display IDs which haven't be used in the alter event.
   *
   * @var array
   */
  protected $viewsDisplayPairs;

  /**
   * The view storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $viewStorage;

  /**
   * The state key value store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * @var RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * Constructs a \Drupal\views\EventSubscriber\RouteSubscriber instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state key value store.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, StateInterface $state, RouteProviderInterface $routeProvider) {
    $this->viewStorage = $entity_type_manager->getStorage('view');
    $this->state = $state;
    $this->routeProvider = $routeProvider;
  }

  /**
   * Resets the internal state of the route subscriber.
   */
  public function reset() {
    $this->viewsDisplayPairs = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = parent::getSubscribedEvents();
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', 9999];

    return $events;
  }

  /**
   * Gets all the views and display IDs using a route.
   */
  protected function getViewsDisplayIDsWithRoute() {
    if (!isset($this->viewsDisplayPairs)) {
      $this->viewsDisplayPairs = [];

      // @todo Convert this method to some service.
      $views = $this->getApplicableViews();
      foreach ($views as $data) {
        list($view_id, $display_id) = $data;
        $this->viewsDisplayPairs[] = $view_id . '.' . $display_id;
      }
      $this->viewsDisplayPairs = array_combine($this->viewsDisplayPairs, $this->viewsDisplayPairs);
    }
    return $this->viewsDisplayPairs;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    foreach ($this->getViewsDisplayIDsWithRoute() as $pair) {
      list($view_id, $display_id) = explode('.', $pair);

      $route_name = "view.{$view_id}.$display_id";

      if ($route = $collection->get($route_name)) {
        $path = $route->getPath();
        $split = explode('/', $path);
        array_pop($split);
        $path = implode('/', $split);

        $pattern = '/' . str_replace('%', '{}', $path);
        if ($routes = $this->routeProvider->getRoutesByPattern($pattern)) {
          foreach ($routes->all() as $name => $base_route) {
            $route->addRequirements($base_route->getRequirements());
            $route->addOptions($base_route->getOptions());
            break;
          }
        }
      }
    }
  }

  /**
   * Returns all views/display combinations with routes.
   *
   * @see \Drupal\views\Views::getApplicableViews()
   */
  protected function getApplicableViews() {
    return Views::getApplicableViews('uses_route');
  }

}
