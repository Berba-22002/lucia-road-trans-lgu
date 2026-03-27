const fetchNotifications = () => {
  fetch('fetch_notifications.php')
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        const badge = document.getElementById('notification-badge');
        const notifList = document.getElementById('notification-list');
        
        if (data.unread_count > 0) {
          if (!badge) {
            const btn = document.getElementById('notification-btn');
            const newBadge = document.createElement('span');
            newBadge.id = 'notification-badge';
            newBadge.className = 'notification-badge bg-lgu-tertiary text-white rounded-full';
            newBadge.textContent = data.unread_count;
            btn.appendChild(newBadge);
          } else {
            badge.textContent = data.unread_count;
          }
        } else if (badge) {
          badge.remove();
        }
        
        if (notifList && data.notifications.length > 0) {
          notifList.innerHTML = data.notifications.map(n => `
            <div class="notification-item ${n.is_read ? '' : 'unread'}" data-notification-id="${n.id}">
              <div class="flex items-start space-x-3">
                <div class="flex-shrink-0">
                  <i class="${{'info': 'text-blue-500 fas fa-info-circle', 'success': 'text-green-500 fas fa-check-circle', 'warning': 'text-yellow-500 fas fa-exclamation-triangle', 'error': 'text-red-500 fas fa-times-circle'}[n.type] || 'text-gray-500 fas fa-bell'}"></i>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-lgu-headline">${n.title}</p>
                  <p class="text-sm text-lgu-paragraph mt-1">${n.message}</p>
                  <p class="text-xs text-gray-400 mt-2">${new Date(n.created_at).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'})}</p>
                </div>
                ${!n.is_read ? '<div class="flex-shrink-0"><div class="w-2 h-2 bg-lgu-button rounded-full"></div></div>' : ''}
              </div>
            </div>
          `).join('');
          attachNotificationListeners();
        }
      }
    })
    .catch(err => console.error('Error fetching notifications:', err));
};

const attachNotificationListeners = () => {
  document.querySelectorAll('.notification-item').forEach(item => {
    item.removeEventListener('click', handleNotificationClick);
    item.addEventListener('click', handleNotificationClick);
  });
};

const handleNotificationClick = function() {
  const notifId = this.getAttribute('data-notification-id');
  fetch('mark_notifications_read.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'mark_single_read', notification_id: notifId })
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      this.classList.remove('unread');
      this.style.opacity = '0.6';
      fetchNotifications();
    }
  })
  .catch(err => console.error('Error:', err));
};

fetchNotifications();
setInterval(fetchNotifications, 5000);
attachNotificationListeners();
