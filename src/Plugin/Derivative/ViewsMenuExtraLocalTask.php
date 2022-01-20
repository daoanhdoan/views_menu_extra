<?php

namespace Drupal\views_menu_extra\Plugin\Derivative;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Menu\LocalTaskManagerInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\State\StateInterface;
use Drupal\views\Plugin\Derivative\ViewsLocalTask;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides local task definitions for all views configured as local tasks.
 */
class ViewsMenuExtraLocalTask extends ViewsLocalTask
{
  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition)
  {
    $this->derivatives = [];
    return $this->derivatives;
  }

  /**
   * Alters base_route and parent_id into the views local tasks.
   */
  public function alterLocalTasks(&$local_tasks)
  {
    $view_route_names = $this->state->get('views.view_route_names');

    foreach ($this->getApplicableMenuViews() as $pair) {
      list($view_id, $display_id) = $pair;
      /** @var \Drupal\views\ViewExecutable $executable */
      $executable = $this->viewStorage->load($view_id)->getExecutable();

      $executable->setDisplay($display_id);
      $menu = $executable->display_handler->getOption('menu');

      // We already have set the base_route for default tabs.
      if (isset($menu['type']) && ($menu['type'] == 'tab')) {
        $plugin_id = 'view.' . $executable->storage->id() . '.' . $display_id;
        $view_route_name = $view_route_names[$executable->storage->id() . '.' . $display_id];

        // Don't add a local task for views which override existing routes.
        if ($view_route_name != $plugin_id) {
          unset($local_tasks[$plugin_id]);
          continue;
        }

        if (!empty($menu['local_task_parent'])) {
          $local_tasks['views_view:' . $plugin_id]['parent_id'] = $menu['local_task_parent'];
          unset($local_tasks['views_view:' . $plugin_id]['base_route']);
        } else if (!empty($menu['local_task_only'])) {
          // Find out the parent route.
          // @todo Find out how to find both the root and parent tab.
          $path = $executable->display_handler->getPath();
          $split = explode('/', $path);
          array_pop($split);
          $path = implode('/', $split);

          $pattern = '/' . str_replace('%', '{}', $path);
          if ($routes = $this->routeProvider->getRoutesByPattern($pattern)) {
            foreach ($routes->all() as $name => $route) {
              $local_tasks['views_view:' . $plugin_id]['parent_id'] = $name;
              break;
            }
          }
        }
      }
    }
  }
}
