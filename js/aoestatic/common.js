var isJQ = (typeof jQuery !== 'undefined');
if (isJQ) jQuery.noConflict();
(function() {
  if (typeof AJAXHOME_URL !== 'undefined') {
    return;
  }

  /**
   * Send ajax request to the Magento store in order to insert dynamic content into the
   * static page delivered from Varnish
   */
  var updateBlocks;
  var callHome;

  var placeholderElems = function placeholderElems() {
    return isJQ ? jQuery('.placeholder') : $$('.placeholder');
  }

  callHome = function callHome() {
    var data = {getBlocks: {}};
    var counter = 0;
    var elems = placeholderElems();

    elems.each(function(el) {
      el = isJQ ? jQuery(this) : $(el);
      var id = isJQ ? el.attr('id') : el.readAttribute('id');
      if (!id) {
        // create dynamic id
        id = 'ph_' + counter;
        if (isJQ) {
          el.attr('id', id)
        } else {
          el.writeAttribute('id', id);
        }
      }
      var rel = isJQ ? el.attr('rel') : el.readAttribute('rel');
      if (rel) {
        data.getBlocks[id] = rel;
        counter++;
      }
    });

    // add current product
    if (typeof CURRENTPRODUCTID !== 'undefined' && CURRENTPRODUCTID) {
      data.currentProductId = CURRENTPRODUCTID;
    }

    if (typeof data.currentProductId !== 'undefined' || counter > 0) {
      if (isJQ) {
        jQuery.get(AJAXHOME_URL, data, updateBlocks, 'json');
      } else {
        $H(data.getBlocks).each(function(block) {
          data['getBlocks[' + block[0] + ']'] = block[1];
        });
        new Ajax.Request(AJAXHOME_URL, {
          method: 'get',
          parameters: data,
          onSuccess: updateBlocks 
        });
      }
    }
  }
  updateBlocks = function updateBlocks(response) {
    response = isJQ ? response : response.transport.responseText.evalJSON();
    for(var id in response.blocks) {
      if (isJQ) {
        jQuery('#' + id).html(response.blocks[id]);
      } else {
        $(id).update(response.blocks[id]);
      }
    }
  }
  if (isJQ) {
    jQuery(document).ready(callHome);
  } else {
    document.observe("dom:loaded", callHome);
  }  
}());
