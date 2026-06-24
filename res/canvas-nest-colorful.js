!function(){
    function getAttr(node, attr, defaultValue) {
        return node.getAttribute(attr) || defaultValue;
    }
    function getAll(tag) {
        return document.getElementsByTagName(tag);
    }
    function initConfig() {
        var scripts = getAll("script"),
            lastScript = scripts[scripts.length - 1];
        return {
            zIndex: getAttr(lastScript, "zIndex", -1),
            opacity: getAttr(lastScript, "opacity", 0.5),
            count: getAttr(lastScript, "count", 99)
        }
    }
    function resize() {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
    }
    function draw() {
        context.clearRect(0, 0, canvas.width, canvas.height);
        var i, j, xDist, yDist, dist, t;
        particles.forEach(function(p, idx){
            p.x += p.vx;
            p.y += p.vy;

            p.vx *= (p.x > canvas.width || p.x < 0) ? -1 : 1;
            p.vy *= (p.y > canvas.height || p.y < 0) ? -1 : 1;

            context.fillStyle = p.color;
            context.beginPath();
            context.arc(p.x, p.y, 1.2, 0, Math.PI * 2, true);
            context.fill();

            for(j = idx + 1; j < allParticles.length; j++) {
                var q = allParticles[j];
                if (q.x === null || q.y === null) continue;
                xDist = p.x - q.x;
                yDist = p.y - q.y;
                dist = xDist * xDist + yDist * yDist;

                if (dist < q.max) {
                    if (q === mouse && dist >= q.max / 2) {
                        p.x -= 0.03 * xDist;
                        p.y -= 0.03 * yDist;
                    }
                    t = (q.max - dist) / q.max;
                    context.beginPath();
                    context.lineWidth = t / 2;
                    context.strokeStyle = p.color;
                    context.moveTo(p.x, p.y);
                    context.lineTo(q.x, q.y);
                    context.stroke();
                }
            }
        });
        requestAnimationFrame(draw);
    }

    var canvas = document.createElement("canvas"),
        config = initConfig(),
        context = canvas.getContext("2d"),
        requestAnimationFrame = window.requestAnimationFrame ||
            window.webkitRequestAnimationFrame ||
            window.mozRequestAnimationFrame ||
            window.oRequestAnimationFrame ||
            window.msRequestAnimationFrame ||
            function(fn){ window.setTimeout(fn, 1000/60); };

    var mouse = {x: null, y: null, max: 20000};

    canvas.id = "c_nest";
    canvas.style.cssText = "position:fixed;top:0;left:0;z-index:" + config.zIndex + ";opacity:" + config.opacity;
    document.body.appendChild(canvas);

    resize();
    window.onresize = resize;

    window.onmousemove = function(e){
        e = e || window.event;
        mouse.x = e.clientX;
        mouse.y = e.clientY;
    };
    window.onmouseout = function(){
        mouse.x = null;
        mouse.y = null;
    };

    var particles = [],
        i,
        random = Math.random;

    for (i = 0; i < config.count; i++) {
        particles.push({
            x: random() * canvas.width,
            y: random() * canvas.height,
            vx: (random() * 2 - 1) / 1.5,
            vy: (random() * 2 - 1) / 1.5,
            max: 6000,
            color: "rgb(" + 
                Math.floor(random() * 255) + "," + 
                Math.floor(random() * 255) + "," + 
                Math.floor(random() * 255) + ")"
        });
    }

    var allParticles = particles.concat([mouse]);
    setTimeout(function(){
        draw();
    }, 100);
}();
