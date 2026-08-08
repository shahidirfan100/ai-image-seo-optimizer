(function($){
  $(document).on('click','.aiiso-test',function(){
    const b=$(this), out=b.next('.aiiso-test-result');
    b.prop('disabled',true); out.text(' Testing…');
    $.post(AIISO.ajax,{action:'aiiso_test_provider',nonce:AIISO.nonce,provider:b.data('provider')})
      .done(function(r){ out.text(' '+(r.success?r.data:'Failed: '+r.data)).toggleClass('ok',!!r.success).toggleClass('bad',!r.success); })
      .fail(function(){out.text(' Request failed.').addClass('bad');})
      .always(function(){b.prop('disabled',false);});
  });
})(jQuery);
