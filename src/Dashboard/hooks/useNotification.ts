import { useCallback } from 'react';
import toast from 'react-hot-toast';

type NotificationType = 'success' | 'error' | 'loading' | 'info';

export const useNotification = () => {
  const showNotification = useCallback((
    message: string, 
    type: NotificationType = 'info',
    duration?: number
  ) => {
    const options = {
      duration: duration || (type === 'error' ? 5000 : 3000),
      position: 'top-right' as const
    };

    switch (type) {
      case 'success':
        toast.success(message, options);
        break;
      case 'error':
        toast.error(message, options);
        break;
      case 'loading':
        toast.loading(message, options);
        break;
      default:
        toast(message, options);
    }
  }, []);

  const dismissAll = useCallback(() => {
    toast.dismiss();
  }, []);

  return {
    showNotification,
    dismissAll,
    success: (message: string, duration?: number) => showNotification(message, 'success', duration),
    error: (message: string, duration?: number) => showNotification(message, 'error', duration),
    loading: (message: string, duration?: number) => showNotification(message, 'loading', duration),
    info: (message: string, duration?: number) => showNotification(message, 'info', duration)
  };
}; 