/**
 * Fix для предупреждения Canvas2D willReadFrequently
 * Патчит getContext для 2d, чтобы Chrome не ругался на multiple getImageData
 */
(function(){
  try {
    var proto = HTMLCanvasElement.prototype;
    var orig = proto.getContext;
    if (!orig || orig._idpgPatched) return;
    proto.getContext = function(type, options){
      if (type === '2d') {
        options = options || {};
        if (options.willReadFrequently === undefined) {
          options.willReadFrequently = true;
        }
      }
      return orig.call(this, type, options);
    };
    proto.getContext._idpgPatched = true;
  } catch(e) {}
})();
