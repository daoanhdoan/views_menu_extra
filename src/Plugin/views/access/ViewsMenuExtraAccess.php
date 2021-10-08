<?php

namespace Drupal\views_menu_extra\Plugin\views\access;

use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Component\Plugin\Exception\MissingValueContextException;
use Drupal\Component\Utility\Html;
use Drupal\Core\Condition\ConditionAccessResolverTrait;
use Drupal\Core\Condition\ConditionPluginCollection;
use Drupal\Core\Executable\ExecutableManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\views\Plugin\views\access\AccessPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;
use Drupal\Core\Session\AccountInterface;

/**
 * Access plugin that provides role-based access control.
 *
 * @ingroup views_access_plugins
 *
 * @ViewsAccess(
 *   id = "views_menu_extra_access",
 *   title = @Translation("Views menu extra access"),
 *   help = @Translation("Custom access")
 * )
 */
class ViewsMenuExtraAccess extends AccessPluginBase {
  use ConditionAccessResolverTrait;
  /**
   * {@inheritdoc}
   */
  protected $usesOptions = TRUE;
  /** @var \Drupal\Core\Condition\ConditionPluginCollection */
  protected $conditionsCollection;
  protected $conditions = [];
  /**
   * @var ExecutableManagerInterface
   */
  protected $conditionManager;

  /**
   * The plugin context handler.
   *
   * @var \Drupal\Core\Plugin\Context\ContextHandlerInterface
   */
  protected $contextHandler;

  /**
   * The context manager service.
   *
   * @var \Drupal\Core\Plugin\Context\ContextRepositoryInterface
   */
  protected $contextRepository;

  /**
   * Constructs a Role object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\user\RoleStorageInterface $role_storage
   *   The role storage.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ExecutableManagerInterface $conditionManager,
                              ContextHandlerInterface $contextHandler, ContextRepositoryInterface $contextRepository) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->conditionManager = $conditionManager;
    $this->contextHandler = $contextHandler;
    $this->contextRepository = $contextRepository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.condition'),
      $container->get('context.handler'),
      $container->get('context.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getConditions() {
    if (!$this->conditionsCollection) {
      $this->conditionsCollection = new ConditionPluginCollection($this->conditionManager, $this->options['conditions']);
    }

    return $this->conditionsCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account) {
    $configurations = !empty($this->options['conditions']) ? $this->options['conditions'] : [];
    if (!$configurations) {
      return TRUE;
    }

    $conditions = [];
    $missing_context = FALSE;
    $missing_value = FALSE;
    $error = null;
    $condition_logic = ($this->options['require_all_conditions'] ? 'and' : 'or');

    foreach ($this->getConditions() as $condition_id => $condition) {
      if ($condition instanceof ContextAwarePluginInterface) {
        try {
          $contexts = $this->contextRepository->getRuntimeContexts(array_values($condition->getContextMapping()));
          $this->contextHandler->applyContextMapping($condition, $contexts);
        }
        catch (MissingValueContextException $e) {dpm($e->getMessage());
          $missing_value = TRUE;
        }
        catch (ContextException $e) {
          $missing_context = TRUE;
        }
      }
      $conditions[$condition_id] = $condition;
    }

    if ($missing_context) {
      $access = FALSE;
    }
    elseif ($missing_value) {
      $access = FALSE;
    }
    else {
      $access = $this->resolveConditions($conditions, $condition_logic);
    }
    return $access;
  }

  /**
   * {@inheritdoc}
   */
  public function alterRouteDefinition(Route $route) {
    if ($this->options['conditions']) {
      $route->setRequirement('_access', 'TRUE');
    }
  }

  public function summaryTitle() {
    return $this->t('Condition settings');
  }

  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['require_all_conditions'] = ['default' => FALSE];
    $options['conditions'] = ['default' => []];

    return $options;
  }

  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $form_state->setTemporaryValue('gathered_contexts', $this->contextRepository->getAvailableContexts());

    $form['require_all_conditions'] = [
      '#type' => 'checkbox',
      '#title' => t('Require all conditions'),
      '#default_value' => $this->options['require_all_conditions'],
      '#description' => t('If checked, all conditions must be met for this view to be active. Otherwise, the first condition that is met will activate this view.')
    ];

    $conditions = $this->conditionManager->getFilteredDefinitions('block_ui', $form_state->getTemporaryValue('gathered_contexts'));

    $form['conditions'] = [
      '#type' => 'vertical_tabs',
      '#parents' => ['conditions'],
      '#attached' => [
        'library' => ['views_menu_extra/admin'],
      ]
    ];

    foreach ($conditions as $condition_id => $condition) {
      $condition = $this->conditionManager->createInstance($condition_id, isset($this->options['conditions'][$condition_id]) ? $this->options['conditions'][$condition_id] : []);
      $element[$condition_id] = [
        '#type' => 'details',
        '#title' => $condition->getPluginDefinition()['label'],
        '#attributes' => ['class' => ['views-menu-extra-condition'], 'id' => Html::cleanCssIdentifier("edit-conditions-" . $condition_id)],
        '#group' => 'conditions',
      ];
      $form_state->set(['conditions', $condition_id], $condition);
      $element[$condition_id]['options'] = $condition->buildConfigurationForm([], $form_state);
      $element[$condition_id]['options']['#parents'] = ['access_options', 'conditions', $condition_id];
      $element[$condition_id]['options']['id'] = [
        '#type' => 'hidden',
        '#value' => $condition_id,
      ];

      $element[$condition_id]['options']['enable'] = [
        '#title' => 'Enable',
        '#type' => 'checkbox',
        '#default_value' => isset($this->options['conditions'][$condition_id]['enable']) ? $this->options['conditions'][$condition_id]['enable'] : FALSE,
        '#weight' => -9999
      ];

      $form['conditions'] += $element;
    }

  }

  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    $conditions = &$form_state->getValue(['access_options', 'conditions'], []);
    foreach ($conditions as $cid => $condition) {
      if (!$condition['enable']) {
        unset($conditions[$cid]);
      }
    }
  }
}
