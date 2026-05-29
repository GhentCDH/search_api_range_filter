<?php

namespace Drupal\views_range_filter_widgets\Plugin\better_exposed_filters\filter;

use Drupal\better_exposed_filters\Plugin\better_exposed_filters\filter\FilterWidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views_range_filter\Plugin\views\filter\RangeFilterBase;

/**
 * BEF widget that replaces the min/max textfields with consecutive dropdowns.
 *
 * @BetterExposedFiltersFilterWidget(
 *   id = "views_range_filter_dropdown",
 *   label = @Translation("Range Dropdown"),
 * )
 */
class RangeDropdown extends FilterWidgetBase {

  // ---------------------------------------------------------------------------
  // Applicability
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public static function isApplicable($filter = NULL, array $filter_options = []): bool {
    return $filter instanceof RangeFilterBase;
  }

  // ---------------------------------------------------------------------------
  // Configuration schema
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'int_range' => [
        'use_auto_range'      => FALSE,
        'min'                 => 1,
        'max'                 => NULL,
        'use_current_year_min' => FALSE,
        'use_current_year_max' => TRUE,
      ],
    ];
  }

  // ---------------------------------------------------------------------------
  // Widget configuration form (shown in the Views UI under BEF settings)
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $saved     = $this->configuration['int_range'] ?? [];
    $filter_id = $this->handler->options['id'];
    $prefix    = 'exposed_form_options[bef][filter][' . $filter_id . '][configuration][int_range]';

    $widget_name  = $prefix . '[use_auto_range]';
    $cur_year_min = $prefix . '[use_current_year_min]';
    $cur_year_max = $prefix . '[use_current_year_max]';

    $form['int_range'] = [
      '#type'  => 'details',
      '#title' => $this->t('Range dropdown options'),
      '#open'  => TRUE,
    ];

    $form['int_range']['use_auto_range'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Auto-calculate range from data'),
      '#default_value' => $saved['use_auto_range'] ?? FALSE,
      '#description'   => $this->t(
        'Automatically determine the minimum and maximum from both fields. '
        . 'Results are cached for one hour — run <code>drush cr</code> after changing fields to refresh. '
        . 'Falls back to manual values if the range exceeds 3000 entries.'
      ),
    ];

    $auto_name = ':input[name="' . $widget_name . '"]';

    $min_state = [
      'visible'  => [$auto_name => ['checked' => FALSE], ':input[name="' . $cur_year_min . '"]' => ['checked' => FALSE]],
      'required' => [$auto_name => ['checked' => FALSE], ':input[name="' . $cur_year_min . '"]' => ['checked' => FALSE]],
    ];

    $max_state = [
      'visible'  => [$auto_name => ['checked' => FALSE], ':input[name="' . $cur_year_max . '"]' => ['checked' => FALSE]],
      'required' => [$auto_name => ['checked' => FALSE], ':input[name="' . $cur_year_max . '"]' => ['checked' => FALSE]],
    ];

    $cur_year_state = [
      'visible' => [$auto_name => ['checked' => FALSE]],
    ];

    $form['int_range']['min'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Minimum value'),
      '#default_value' => $saved['min'] ?? 1,
      '#size'          => 10,
      '#description'   => $this->t('The difference between maximum and minimum may not exceed 3000.'),
      '#states'        => $min_state,
    ];

    $form['int_range']['use_current_year_min'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Use current year as minimum'),
      '#default_value' => $saved['use_current_year_min'] ?? FALSE,
      '#states'        => $cur_year_state,
    ];

    $form['int_range']['max'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Maximum value'),
      '#default_value' => $saved['max'] ?? (int) date('Y'),
      '#size'          => 10,
      '#states'        => $max_state,
    ];

    $form['int_range']['use_current_year_max'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Use current year as maximum'),
      '#default_value' => $saved['use_current_year_max'] ?? TRUE,
      '#states'        => $cur_year_state,
    ];

    return $form;
  }

  // ---------------------------------------------------------------------------
  // Exposed form alteration
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function exposedFormAlter(array &$form, FormStateInterface $form_state): void {
    parent::exposedFormAlter($form, $form_state);

    /** @var \Drupal\views\Plugin\views\filter\FilterPluginBase $filter */
    $filter     = $this->handler;
    $field_id   = $filter->options['expose']['identifier'] ?? '';
    $wrapper_id = $field_id . '_wrapper';

    if (!$field_id) {
      return;
    }

    // After parent::exposedFormAlter(), BEF wraps elements that have min/max
    // children: $form[$field_id] = ['#type' => 'container', $field_id => $orig].
    // Try the nested (wrapped) path first, then unwrapped fallbacks.
    if (isset($form[$field_id][$field_id]['min'])) {
      $element = &$form[$field_id][$field_id];
    }
    elseif (isset($form[$wrapper_id][$wrapper_id][$field_id]['min'])) {
      $element = &$form[$wrapper_id][$wrapper_id][$field_id];
    }
    elseif (isset($form[$field_id]['min'])) {
      $element = &$form[$field_id];
    }
    elseif (isset($form[$wrapper_id][$field_id]['min'])) {
      $element = &$form[$wrapper_id][$field_id];
    }
    else {
      return;
    }

    $options = $this->buildDropdownOptions($this->configuration['int_range'] ?? []);

    if (empty($options)) {
      return;
    }

    $empty = $this->t('- Any -');

    foreach (['min', 'max'] as $key) {
      if (!isset($element[$key])) {
        continue;
      }
      $existing = $element[$key];
      $element[$key] = [
        '#type'          => 'select',
        '#title'         => $existing['#title'] ?? '',
        '#options'       => $options,
        '#default_value' => $existing['#default_value'] ?? '',
        '#empty_option'  => $empty,
      ];
    }
    unset($element);
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /**
   * Builds the ordered dropdown options array.
   *
   * Options are listed in descending order (newest / largest first).
   *
   * @param array $int_range
   *   Configuration with keys: use_auto_range, min, max,
   *   use_current_year_min, use_current_year_max.
   *
   * @return array
   *   Array keyed and valued by integer, e.g. [2024 => 2024, 2023 => 2023, …].
   */
  protected function buildDropdownOptions(array $int_range): array {
    if (!empty($int_range['use_auto_range'])) {
      /** @var \Drupal\views_range_filter\Plugin\views\filter\RangeFilterBase $filter */
      $filter      = $this->handler;
      $start_field = $filter->options['start_field'] ?? '';
      $end_field   = $filter->options['end_field']   ?? '';
      $single_mode = $start_field === $end_field;

      $start_auto = $start_field ? $filter->resolveAutoMinMax($start_field) : NULL;
      $end_auto   = (!$single_mode && $end_field) ? $filter->resolveAutoMinMax($end_field) : NULL;

      $year_vals = [];
      if ($start_auto !== NULL) {
        $t           = $start_auto['type'] ?? 'integer';
        $year_vals[] = $this->extractYearOrInt($start_auto['min'], $t, 'min');
        $year_vals[] = $this->extractYearOrInt($start_auto['max'], $t, 'max');
      }
      if ($end_auto !== NULL) {
        $t           = $end_auto['type'] ?? 'integer';
        $year_vals[] = $this->extractYearOrInt($end_auto['min'], $t, 'min');
        $year_vals[] = $this->extractYearOrInt($end_auto['max'], $t, 'max');
      }

      if (!empty($year_vals)) {
        $int_range['min']                  = min($year_vals);
        $int_range['max']                  = max($year_vals);
        $int_range['use_current_year_min'] = FALSE;
        $int_range['use_current_year_max'] = FALSE;
      }
    }

    $min = ($int_range['use_current_year_min'] ?? FALSE)
      ? (int) date('Y')
      : (isset($int_range['min']) && $int_range['min'] !== '' ? (int) $int_range['min'] : NULL);

    $max = ($int_range['use_current_year_max'] ?? FALSE)
      ? (int) date('Y')
      : (isset($int_range['max']) && $int_range['max'] !== '' ? (int) $int_range['max'] : NULL);

    if ($min === NULL || $max === NULL) {
      return [];
    }

    if ($min > $max) {
      [$min, $max] = [$max, $min];
    }

    if ($min < -9999 || $max > 9999 || ($max - $min) > 3000) {
      \Drupal::logger('views_range_filter_widgets')->warning(
        'Dropdown range @min–@max exceeds limits; falling back to empty. Clear the data cache and check field configuration.',
        ['@min' => $min, '@max' => $max]
      );
      return [];
    }

    $values = array_reverse(range($min, $max));
    return array_combine($values, $values);
  }

  /**
   * Extracts a year or integer from a raw field value.
   *
   * For timestamp or date fields (where the raw value is a Unix timestamp or
   * a date string), this converts to the year integer. For plain integers it
   * returns the value as-is.
   *
   * @param mixed  $value       Raw field value.
   * @param string $field_type  SAPI field type: 'date', 'integer', etc.
   *
   * @return int
   */
  protected function extractYearOrInt(mixed $value, string $field_type = 'integer', string $bound = 'min'): int {
    if (is_numeric($value)) {
      $int = $bound === 'min' ? (int) floor((float) $value) : (int) ceil((float) $value);
      if ($field_type === 'date' || abs($int) > 9999) {
        try {
          return (int) (new \DateTime('@' . $int))->format('Y');
        }
        catch (\Exception $e) {
          return $int;
        }
      }
      return $int;
    }

    try {
      return (int) (new \DateTime((string) $value, new \DateTimeZone('UTC')))->format('Y');
    }
    catch (\Exception $e) {
      return (int) substr((string) $value, 0, 4);
    }
  }

}
