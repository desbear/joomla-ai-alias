/**
 * AI Alias: "AI" button next to the article alias field.
 *
 * @copyright  (C) 2026 GSS
 * @license    GNU General Public License version 2 or later
 */
((Joomla, document) => {
  'use strict';

  const value = (id) => {
    const el = document.getElementById(id);
    return el ? el.value : '';
  };

  // com_content uses jform_articletext, FLEXIcontent the field ids configured in the plugin
  const articleText = (ids) => {
    const instances = (Joomla.editors && Joomla.editors.instances) || {};

    for (let i = 0; i < ids.length; i += 1) {
      const editor = instances[ids[i]];

      if (editor && typeof editor.getValue === 'function') {
        try {
          const text = editor.getValue();

          if (text) {
            return text;
          }
        } catch (e) {
          // fall through to the plain field
        }
      }

      const plain = value(ids[i]);

      if (plain) {
        return plain;
      }
    }

    return '';
  };

  // FLEXIcontent items may only have the multi-category field filled in
  const categoryId = () => {
    const main = value('jform_catid');

    if (main) {
      return main;
    }

    const cats = document.getElementById('jform_cid') || document.querySelector('[name="jform[cid][]"]');

    if (cats && cats.selectedOptions && cats.selectedOptions.length) {
      return cats.selectedOptions[0].value;
    }

    return cats ? cats.value : '';
  };

  const init = () => {
    const alias = document.getElementById('jform_alias');
    const options = Joomla.getOptions('plg_system_aialias');

    if (!alias || !options || alias.dataset.aialias) {
      return;
    }

    alias.dataset.aialias = '1';

    // Long error details must wrap instead of running off the message bar
    const style = document.createElement('style');
    style.textContent = '#system-message-container .alert-message{overflow-wrap:anywhere;word-break:break-word}';
    document.head.appendChild(style);

    let group = alias.closest('.input-group');

    if (!group) {
      group = document.createElement('div');
      group.className = 'input-group';
      alias.parentNode.insertBefore(group, alias);
      group.appendChild(alias);
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-primary';
    button.innerHTML = '<span class="icon-lightbulb" aria-hidden="true"></span> ' + Joomla.Text._('PLG_SYSTEM_AIALIAS_JS_BUTTON');
    group.appendChild(button);

    const label = button.innerHTML;

    button.addEventListener('click', () => {
      const title = value('jform_title').trim();

      if (!title) {
        Joomla.renderMessages({ warning: [Joomla.Text._('PLG_SYSTEM_AIALIAS_JS_NEED_TITLE')] });
        return;
      }

      if (alias.value.trim() && !window.confirm(Joomla.Text._('PLG_SYSTEM_AIALIAS_JS_CONFIRM_OVERWRITE'))) {
        return;
      }

      const body = new FormData();
      body.append('title', title);
      body.append('text', articleText(options.contentIds || ['jform_articletext']));
      body.append('catid', categoryId());
      body.append('id', value('jform_id'));
      body.append('language', value('jform_language') || '*');
      body.append(Joomla.getOptions('csrf.token'), '1');

      button.disabled = true;
      button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ' + Joomla.Text._('PLG_SYSTEM_AIALIAS_JS_WORKING');

      Joomla.request({
        url: options.url,
        method: 'POST',
        data: body,
        promise: true,
      })
        .then((xhr) => {
          const response = JSON.parse(xhr.responseText);
          // com_ajax returns the plugin results as a list
          const data = Array.isArray(response.data) ? response.data[0] : response.data;

          if (!response.success || !data || !data.alias) {
            throw new Error(response.message || Joomla.Text._('PLG_SYSTEM_AIALIAS_JS_ERROR'));
          }

          alias.value = data.alias;
          alias.dispatchEvent(new Event('change', { bubbles: true }));

          if (data.source === 'ai') {
            Joomla.renderMessages({ success: [Joomla.Text._('PLG_SYSTEM_AIALIAS_JS_DONE_AI')] });
          } else {
            Joomla.renderMessages({
              warning: [Joomla.Text._('PLG_SYSTEM_AIALIAS_JS_DONE_FALLBACK').replace('%s', data.reason || '')],
            });
          }
        })
        .catch((error) => {
          let message = error && error.message ? error.message : '';

          try {
            message = JSON.parse(error.responseText).message || message;
          } catch (e) {
            // not a JSON error response
          }

          Joomla.renderMessages({ error: [message || Joomla.Text._('PLG_SYSTEM_AIALIAS_JS_ERROR')] });
        })
        .finally(() => {
          button.disabled = false;
          button.innerHTML = label;
        });
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(Joomla, document);
