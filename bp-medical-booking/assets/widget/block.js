/* Gutenberg-блок "Онлайн-запис BP Medical" (без збірки). Рендер на сервері через шорткод [bp_booking]. */
(function (wp) {
  if (!wp || !wp.blocks) return;
  var el = wp.element.createElement;
  var be = wp.blockEditor;
  var c = wp.components;
  wp.blocks.registerBlockType('bp/booking', {
    title: 'Онлайн-запис BP Medical',
    icon: 'calendar-alt',
    category: 'widgets',
    attributes: {
      doctor: { type: 'string', default: '' },
      specialty: { type: 'string', default: '' },
      entry: { type: 'string', default: '' },
      mode: { type: 'string', default: 'inline' },
      label: { type: 'string', default: 'Записатися онлайн' }
    },
    edit: function (props) {
      var a = props.attributes;
      var set = function (k) { return function (v) { var o = {}; o[k] = v; props.setAttributes(o); }; };
      return el('div', { className: 'bpb-block-placeholder', style: { border: '1px dashed #8a959f', padding: '16px', borderRadius: '8px' } },
        el(be.InspectorControls, null,
          el(c.PanelBody, { title: 'Налаштування запису' },
            el(c.TextControl, { label: 'Slug лікаря (відкрити одразу вибір дати)', value: a.doctor, onChange: set('doctor') }),
            el(c.TextControl, { label: 'Slug спеціальності (пропустити вибір спеціальності)', value: a.specialty, onChange: set('specialty') }),
            el(c.SelectControl, { label: 'Джерело для аналітики (entry)', value: a.entry, onChange: set('entry'), options: [
              { label: 'авто', value: '' }, { label: 'сторінка лікаря', value: 'doctor_page' },
              { label: 'сторінка спеціальності', value: 'specialty_page' }, { label: 'шапка', value: 'header' }, { label: 'промо', value: 'promo' }
            ] }),
            el(c.SelectControl, { label: 'Вигляд', value: a.mode, onChange: set('mode'), options: [
              { label: 'Віджет на сторінці', value: 'inline' }, { label: 'Кнопка → модальне вікно', value: 'button' }
            ] }),
            a.mode === 'button' ? el(c.TextControl, { label: 'Текст кнопки', value: a.label, onChange: set('label') }) : null
          )
        ),
        el('strong', null, 'Онлайн-запис BP Medical'),
        el('p', { style: { margin: '4px 0 0' } },
          (a.doctor ? 'Лікар: ' + a.doctor + '. ' : '') + (a.specialty ? 'Спеціальність: ' + a.specialty + '. ' : '') +
          (a.mode === 'button' ? 'Кнопка «' + a.label + '»' : 'Віджет відображається на сайті.'))
      );
    },
    save: function () { return null; }
  });
})(window.wp);
