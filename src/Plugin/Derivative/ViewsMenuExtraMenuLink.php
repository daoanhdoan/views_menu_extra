<?php

namespace Drupal\views_menu_extra\Plugin\Derivative;

use Drupal\views\Plugin\Derivative\ViewsMenuLink;
use Drupal\views\Views;
use Drupal\Core\Entity\EntityStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides menu links for Views.
 *
 * @see \Drupal\views\Plugin\Menu\ViewsMenuLink
 */
class ViewsMenuExtraMenuLink extends ViewsMenuLink {
  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $links = [];

    $views = Views::getApplicableViews('uses_menu_links');

    foreach ($views as $data) {
      list($view_id, $display_id) = $data;
      /** @var \Drupal\views\ViewExecutable $executable */
      $executable = $this->viewStorage->load($view_id)->getExecutable();
      $executable->initDisplay();
      $display = $executable->displayHandlers->get($display_id);
      $menu_link_id = 'views.' . $view_id . "." . str_replace('/', '.', $display_id);

      $menu = $display->getOption('menu');
      if (!empty($menu['type']) && (in_array($menu['type'], ['tab', 'default tab']) && !empty($menu['link']))) {
        $links[$menu_link_id] = [];
        // Some views might override existing paths, so we have to set the route
        // name based upon the altering.
        $links[$menu_link_id] = [
          'route_name' => $display->getRouteName(),
          // Identify URL embedded arguments and correlate them to a handler.
          'load arguments'  => [$view_id, $display_id, '%index'],
          'id' => $menu_link_id,
        ];

        $links[$menu_link_id]['title'] = $menu['title'];
        $links[$menu_link_id]['description'] = $menu['description'];
        $links[$menu_link_id]['parent'] = $menu['parent'];
        $links[$menu_link_id]['enabled'] = $menu['enabled'];
        $links[$menu_link_id]['expanded'] = $menu['expanded'];

        if (isset($menu['weight'])) {
          $links[$menu_link_id]['weight'] = intval($menu['weight']);
        }

        // Insert item into the proper menu.
        $links[$menu_link_id]['menu_name'] = $menu['menu_name'];
        // Keep track of where we came from.
        $links[$menu_link_id]['metadata'] = [
          'view_id' => $view_id,
          'display_id' => $display_id,
        ];

        $links[$menu_link_id] = $links[$menu_link_id] + $base_plugin_definition;
      }
    }

    return $links;
  }

}
