/**
 * SweetAlert2 is the app's alert style. Three entry points, all on window:
 *
 *   notify({ type, message, title, persist, small })  success | error | warning | info
 *       Livewire components reach it with $this->dispatch('toast', message: ..., type: ...)
 *       (see components/toaster.blade.php). Success closes itself unless persist is set;
 *       small shows a corner toast instead of a modal, for quick repeated actions.
 *
 *   confirmAlert({ title, text, confirmText, icon })  -> Promise<boolean>
 *       Used instead of wire:confirm, e.g.
 *       x-on:click="confirmAlert({...}).then(ok => ok && $wire.import())"
 *
 *   uploadAlert.start(name) / .progress(pct) / .checking() / .done() / .failed(message)
 *       The modal shown while a file uploads and is checked.
 *
 *   busyAlert(title, text, promise)
 *       A loading modal while a long server action runs (e.g. transmitting).
 */
import Swal from 'sweetalert2';

const button = 'inline-flex h-11 items-center justify-center rounded-[11px] px-5 text-sm font-bold mx-1.5';

const Alert = Swal.mixin({
    buttonsStyling: false,
    reverseButtons: true,
    customClass: {
        popup: 'rounded-2xl! font-sans!',
        title: 'text-[20px]! font-extrabold! text-ink!',
        htmlContainer: 'text-[14.5px]! text-ink-soft!',
        confirmButton: `${button} bg-green-700 text-white hover:bg-green-800`,
        cancelButton: `${button} border border-field bg-white text-ink-soft hover:border-green-700`,
        timerProgressBar: 'bg-green-600!',
    },
});

const TITLES = { success: 'Done', error: 'Something went wrong', warning: 'Please check', info: 'Heads up' };

// What is on screen, so uploadAlert.done() never closes a result alert that replaced it
let showing = null;

// Small corner toast for quick, frequent confirmations (e.g. ticking a module)
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 2500,
    timerProgressBar: true,
    customClass: { popup: 'rounded-xl! font-sans!', title: 'text-[14px]! font-semibold! text-ink!', timerProgressBar: 'bg-green-600!' },
});

window.notify = ({ type = 'success', message = '', title = null, persist = false, small = false } = {}) => {
    const icon = TITLES[type] ? type : 'info';

    if (small) {
        return Toast.fire({ icon, title: message || title });
    }

    const autoClose = icon === 'success' && !persist;
    showing = 'notice';

    return Alert.fire({
        icon,
        title: title || TITLES[icon],
        text: message,
        timer: autoClose ? 2600 : undefined,
        timerProgressBar: autoClose,
        showConfirmButton: !autoClose,
        confirmButtonText: 'OK',
    });
};

window.confirmAlert = async ({ title = 'Are you sure?', text = '', confirmText = 'Yes, continue', icon = 'question' } = {}) => {
    showing = 'confirm';
    const result = await Alert.fire({
        icon,
        title,
        text,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Cancel',
        focusCancel: true,
    });

    return result.isConfirmed;
};

/**
 * A blocking "working on it" modal while a server action runs. The server's
 * result alert replaces it; if none arrives, it closes when the promise settles.
 *   busyAlert('Transmitting...', 'Sending 20 records', $wire.transmit())
 */
window.busyAlert = (title, text, promise) => {
    showing = 'busy';
    Alert.fire({ title, text, allowOutsideClick: false, allowEscapeKey: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });

    return Promise.resolve(promise).finally(() => {
        if (showing === 'busy') {
            showing = null;
            Swal.close();
        }
    });
};

window.uploadAlert = {
    start(name) {
        showing = 'upload';
        Alert.fire({
            title: 'Uploading file',
            html: `<p class="mb-3 truncate font-semibold text-ink" data-upload-name></p>
                   <div class="h-2.5 overflow-hidden rounded-full bg-[#EEF5F0]"><div class="h-2.5 rounded-full bg-green-700 transition-all" style="width:0%" data-upload-bar></div></div>
                   <p class="mt-2 text-[13px] text-muted" data-upload-pct>0%</p>`,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: (popup) => { popup.querySelector('[data-upload-name]').textContent = name || 'Your file'; },
        });
    },
    progress(pct) {
        if (showing !== 'upload') return;
        const popup = Swal.getPopup();
        if (!popup) return;
        const bar = popup.querySelector('[data-upload-bar]');
        const label = popup.querySelector('[data-upload-pct]');
        if (bar) bar.style.width = pct + '%';
        if (label) label.textContent = pct + '%';
        if (pct >= 100) this.checking();
    },
    checking() {
        if (showing !== 'upload') return;
        Swal.update({ title: 'Checking every row…', html: '<p class="text-[14px] text-ink-soft">Nothing is saved until you press Import.</p>' });
        Swal.showLoading();
    },
    // Close the upload modal - unless the server's result alert has already replaced it
    done() {
        if (showing === 'upload') {
            showing = null;
            Swal.close();
        }
    },
    failed(message) {
        window.notify({ type: 'error', title: 'Upload failed', message });
    },
};
