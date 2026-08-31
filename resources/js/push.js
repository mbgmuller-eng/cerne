/**
 * Inscrição de notificação push do navegador. Chamado sob demanda (ver
 * my-account.blade.php) quando a pessoa liga o interruptor de push — não
 * na carga da página, pra não pedir permissão sem a pessoa ter pedido.
 */
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);

    return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
}

window.cerneSubscribeToPush = async function () {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return;
    }

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
        return;
    }

    const key = document.querySelector('meta[name="vapid-public-key"]')?.content;
    if (!key) {
        return;
    }

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(key),
    });

    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    await fetch('/preferencias/push', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            Accept: 'application/json',
        },
        body: JSON.stringify(subscription.toJSON()),
    });
};
