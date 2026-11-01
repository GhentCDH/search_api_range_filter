/**
 * @file
 * The slider widget of the range filter: noUiSlider on the number fields.
 *
 * The "from" and "to" number fields stay: they are the fallback without
 * JavaScript, and the slider and the fields keep each other up to date. A
 * handle at the lowest or highest value means no limit on that side, as an
 * empty field does.
 */
((Drupal, once) => {
  /**
   * Returns the label of a form element, for the handle of the slider.
   */
  const labelOf = (input) => {
    const label = input.id ? document.querySelector(`label[for="${input.id}"]`) : null;
    return label ? label.textContent.trim() : '';
  };

  Drupal.behaviors.searchApiRangeSlider = {
    attach(context) {
      if (typeof window.noUiSlider === 'undefined') {
        return;
      }
      once('search-api-range-slider', '[data-search-api-range-slider]', context).forEach((wrapper) => {
        const settings = JSON.parse(wrapper.dataset.searchApiRangeSlider);
        const inputs = wrapper.querySelectorAll('input[type="number"]');
        if (inputs.length !== 2) {
          return;
        }
        const [from, to] = inputs;
        const bound = [settings.min, settings.max];
        const valueOf = (input, index) => {
          const value = Number(input.value);
          return input.value === '' || Number.isNaN(value) ? bound[index] : value;
        };

        const slider = document.createElement('div');
        slider.className = 'search-api-range-filter-slider__slider';
        wrapper.insertBefore(slider, wrapper.firstChild);
        window.noUiSlider.create(slider, {
          start: [valueOf(from, 0), valueOf(to, 1)],
          connect: true,
          step: settings.step,
          range: { min: settings.min, max: settings.max },
          tooltips: true,
          format: {
            to: (value) => String(Math.round(value)),
            from: (value) => Number(value),
          },
          handleAttributes: [{ 'aria-label': labelOf(from) }, { 'aria-label': labelOf(to) }],
        });

        // The slider fills in the fields, empty at a bound.
        slider.noUiSlider.on('change', (values) => {
          [from, to].forEach((input, index) => {
            const value = Number(values[index]);
            input.value = value === bound[index] ? '' : String(value);
            input.dispatchEvent(new Event('change', { bubbles: true }));
          });
        });
        // The fields move the handles.
        [from, to].forEach((input, index) => {
          input.addEventListener('change', () => {
            slider.noUiSlider.setHandle(index, valueOf(input, index), false);
          });
        });
        wrapper.classList.add('search-api-range-filter-slider--enhanced');
      });
    },
  };
})(Drupal, once);
