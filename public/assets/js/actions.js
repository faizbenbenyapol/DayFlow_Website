/* =====================================================
   Declarative actions
   Replaces inline on* attributes so the page needs no 'unsafe-inline' in its
   script-src, which is what stops an injected attribute from ever running.

   Markup carries what to do rather than the code to do it:

     data-act="openEditTask"  data-args="[42]"      call window.openEditTask(42)
     data-act="togglePw"      data-args='["pw","$el"]'   $el becomes the element
     data-act="handleKey"     data-args='["$event"]'     $event becomes the event
     data-args='["$value"]'   also $checked and $files, for what `this` reached
     data-nav="/tasks"                                    navigate there
     data-click="#fileInput"                              forward a click
     data-stop                                            stop the event bubbling
     data-prevent                                         swallow the default only
     data-on="change"                                     listen for that instead of click

   A form with data-act submits through it, so preventDefault is automatic.
===================================================== */
(function initActions() {
    const EVENTS = ['click', 'change', 'input', 'submit', 'keydown', 'keyup', 'blur', 'focus'];

    function resolve(name) {
        // Supports "obj.method" as well as a bare function name.
        return name.split('.').reduce((carrier, part) => (carrier ? carrier[part] : undefined), window);
    }

    function argumentsFor(element, event) {
        const raw = element.dataset.args;
        if (!raw) return [];

        let parsed;
        try {
            parsed = JSON.parse(raw);
        } catch {
            console.warn('data-args is not valid JSON:', raw, element);
            return [];
        }

        const list = Array.isArray(parsed) ? parsed : [parsed];
        return list.map(value => {
            switch (value) {
                case '$el':      return element;
                case '$event':   return event;
                // The three things a handler used to reach for through `this`.
                case '$value':   return element.value;
                case '$checked': return element.checked;
                case '$files':   return element.files;
                default:         return value;
            }
        });
    }

    function run(element, event) {
        // Navigation and click-forwarding are common enough to be their own
        // attributes rather than one-line functions.
        const nav = element.dataset.nav;
        if (nav) {
            window.location.href = nav;
            return;
        }

        const forward = element.dataset.click;
        if (forward) {
            document.querySelector(forward)?.click();
            return;
        }

        const name = element.dataset.act;
        if (!name) return;

        const fn = resolve(name);
        if (typeof fn !== 'function') {
            console.warn('data-act names something that is not a function:', name, element);
            return;
        }

        fn.apply(element, argumentsFor(element, event));
    }

    EVENTS.forEach(type => {
        document.addEventListener(type, event => {
            const element = event.target.closest('[data-act],[data-nav],[data-click],[data-stop],[data-prevent]');
            if (!element) return;

            // An element only reacts to the event it asked for. Anything
            // without data-on is a click, which is the overwhelming majority.
            const wanted = element.dataset.on || (element.tagName === 'FORM' ? 'submit' : 'click');
            if (wanted !== type) return;

            // A form never reloads the page; data-prevent says the same for
            // anything else that would otherwise act on its own.
            if (type === 'submit' || element.dataset.prevent !== undefined) event.preventDefault();
            // A row inside a clickable card marks itself so the card's own
            // handler does not also fire.
            if (element.dataset.stop !== undefined) event.stopPropagation();
            run(element, event);
        }, type === 'blur' || type === 'focus');
        // Blur and focus do not bubble, so those two listen during capture.
    });
})();
