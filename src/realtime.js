import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useEffect, useState } from 'react'

window.Pusher = Pusher

export function connectRealtime(userId) {
  const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY || 'workdesk',
    wsHost: window.location.hostname,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT) || 8091,
    wssPort: Number(import.meta.env.VITE_REVERB_PORT) || 8091,
    forceTLS: window.location.protocol === 'https:',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/api/broadcasting/auth',
  })

  echo.private(`user.${userId}`).listen('.workdesk.updated', (event) => {
    window.dispatchEvent(new CustomEvent('workdesk:updated', { detail: event }))
  })
  echo.connector.pusher.connection.bind('connected', () => {
    window.dispatchEvent(new CustomEvent('workdesk:updated', {
      detail: { resource: '*' },
    }))
  })

  return () => echo.disconnect()
}

export function useRealtimeRefresh(...resources) {
  const [version, setVersion] = useState(0)
  const resourceKey = resources.join('|')

  useEffect(() => {
    const handleUpdate = (event) => {
      const resource = event.detail?.resource
      if (resource === '*' || resourceKey.split('|').includes(resource)) {
        setVersion((current) => current + 1)
      }
    }
    window.addEventListener('workdesk:updated', handleUpdate)

    return () => window.removeEventListener('workdesk:updated', handleUpdate)
  }, [resourceKey])

  return version
}
