import { format, parseISO, formatDistanceToNow } from 'date-fns';

/**
 * Format numbers with appropriate units and precision
 */
export const formatNumber = (value: number, precision: number = 0): string => {
  if (value >= 1000000) {
    return `${(value / 1000000).toFixed(precision)}M`;
  }
  if (value >= 1000) {
    return `${(value / 1000).toFixed(precision)}K`;
  }
  return value.toFixed(precision);
};

/**
 * Format metric values with appropriate context
 */
export const formatMetricValue = (value: number, metric?: string): string => {
  switch (metric) {
    case 'percentage':
    case 'coverage':
      return `${(value * 100).toFixed(1)}%`;
    case 'score':
      return value.toFixed(1);
    case 'time':
    case 'hours':
      return `${value.toFixed(1)}h`;
    case 'days':
      return `${value.toFixed(0)}d`;
    case 'bytes':
      return formatFileSize(value);
    default:
      return formatNumber(value, 1);
  }
};

/**
 * Format file sizes
 */
export const formatFileSize = (bytes: number): string => {
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let size = bytes;
  let unitIndex = 0;
  
  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024;
    unitIndex++;
  }
  
  return `${size.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
};

/**
 * Format dates for different contexts
 */
export const formatDate = (date: Date | string, formatType: 'short' | 'long' | 'relative' = 'long'): string => {
  const dateObj = typeof date === 'string' ? parseISO(date) : date;
  
  switch (formatType) {
    case 'short':
      return format(dateObj, 'MMM dd');
    case 'relative':
      return formatDistanceToNow(dateObj, { addSuffix: true });
    default:
      return format(dateObj, 'MMM dd, yyyy HH:mm');
  }
};

/**
 * Get color based on quality score
 */
export const getScoreColor = (score: number, theme: any): string => {
  if (score >= 8) return theme.palette.success.main;
  if (score >= 6) return theme.palette.warning.main;
  if (score >= 4) return theme.palette.error.light;
  return theme.palette.error.main;
};

/**
 * Get color based on severity level
 */
export const getSeverityColor = (severity: string, theme: any): string => {
  switch (severity.toLowerCase()) {
    case 'critical':
      return theme.palette.error.main;
    case 'error':
      return theme.palette.error.light;
    case 'warning':
      return theme.palette.warning.main;
    case 'info':
      return theme.palette.info.main;
    default:
      return theme.palette.grey[500];
  }
};

/**
 * Get icon for category
 */
export const getCategoryIcon = (category: string): string => {
  switch (category.toLowerCase()) {
    case 'security':
      return '🔒';
    case 'performance':
      return '⚡';
    case 'maintainability':
      return '🔧';
    case 'logic':
      return '🧠';
    case 'style':
      return '🎨';
    case 'testing':
      return '🧪';
    case 'documentation':
      return '📚';
    default:
      return '📋';
  }
};

/**
 * Calculate percentage change
 */
export const calculatePercentageChange = (current: number, previous: number): number => {
  if (previous === 0) return current > 0 ? 100 : 0;
  return ((current - previous) / Math.abs(previous)) * 100;
};

/**
 * Format percentage with sign
 */
export const formatPercentageChange = (change: number): string => {
  const sign = change > 0 ? '+' : '';
  return `${sign}${change.toFixed(1)}%`;
};

/**
 * Generate color palette
 */
export const generateColorPalette = (count: number, theme: any): string[] => {
  const baseColors = [
    theme.palette.primary.main,
    theme.palette.secondary.main,
    theme.palette.error.main,
    theme.palette.warning.main,
    theme.palette.info.main,
    theme.palette.success.main
  ];

  if (count <= baseColors.length) {
    return baseColors.slice(0, count);
  }

  // Generate additional colors by lightening/darkening base colors
  const extendedColors = [...baseColors];
  const iterations = Math.ceil(count / baseColors.length) - 1;

  for (let i = 1; i <= iterations; i++) {
    baseColors.forEach(color => {
      const factor = 0.2 * i;
      extendedColors.push(lightenColor(color, factor));
    });
  }

  return extendedColors.slice(0, count);
};

/**
 * Lighten/darken color
 */
export const lightenColor = (color: string, factor: number): string => {
  const num = parseInt(color.replace('#', ''), 16);
  const amt = Math.round(2.55 * factor * 100);
  const R = (num >> 16) + amt;
  const G = (num >> 8 & 0x00FF) + amt;
  const B = (num & 0x0000FF) + amt;
  
  return '#' + (0x1000000 + (R < 255 ? R < 1 ? 0 : R : 255) * 0x10000 +
    (G < 255 ? G < 1 ? 0 : G : 255) * 0x100 +
    (B < 255 ? B < 1 ? 0 : B : 255)).toString(16).slice(1);
};

/**
 * Format duration in human readable format
 */
export const formatDuration = (minutes: number): string => {
  if (minutes < 60) {
    return `${Math.round(minutes)}m`;
  }
  if (minutes < 1440) {
    const hours = Math.floor(minutes / 60);
    const remainingMinutes = Math.round(minutes % 60);
    return remainingMinutes > 0 ? `${hours}h ${remainingMinutes}m` : `${hours}h`;
  }
  const days = Math.floor(minutes / 1440);
  const remainingHours = Math.floor((minutes % 1440) / 60);
  return remainingHours > 0 ? `${days}d ${remainingHours}h` : `${days}d`;
};

/**
 * Truncate text with ellipsis
 */
export const truncateText = (text: string, maxLength: number): string => {
  if (text.length <= maxLength) return text;
  return text.substring(0, maxLength - 3) + '...';
};

/**
 * Format code snippets for display
 */
export const formatCodeSnippet = (code: string, maxLines: number = 5): string => {
  const lines = code.split('\n');
  if (lines.length <= maxLines) return code;
  
  const truncatedLines = lines.slice(0, maxLines);
  truncatedLines.push(`... (${lines.length - maxLines} more lines)`);
  return truncatedLines.join('\n');
};

/**
 * Get trend arrow icon
 */
export const getTrendArrow = (direction: string): string => {
  switch (direction) {
    case 'improving':
      return '↗️';
    case 'declining':
      return '↘️';
    case 'stable':
      return '→';
    default:
      return '—';
  }
};

/**
 * Calculate moving average
 */
export const calculateMovingAverage = (values: number[], windowSize: number = 7): number[] => {
  const result: number[] = [];
  
  for (let i = 0; i < values.length; i++) {
    const start = Math.max(0, i - windowSize + 1);
    const window = values.slice(start, i + 1);
    const average = window.reduce((sum, val) => sum + val, 0) / window.length;
    result.push(average);
  }
  
  return result;
};

/**
 * Detect trend direction from slope
 */
export const detectTrendDirection = (slope: number, threshold: number = 0.1): 'up' | 'down' | 'stable' => {
  if (Math.abs(slope) < threshold) return 'stable';
  return slope > 0 ? 'up' : 'down';
};

/**
 * Calculate trend direction and magnitude
 */
export const calculateTrend = (values: number[]): { direction: 'up' | 'down' | 'stable'; change: number } => {
  if (values.length < 2) {
    return { direction: 'stable', change: 0 };
  }
  
  const recent = values.slice(-Math.min(7, values.length));
  const older = values.slice(0, Math.max(0, values.length - recent.length));
  
  if (older.length === 0) {
    return { direction: 'stable', change: 0 };
  }
  
  const recentAvg = recent.reduce((sum, val) => sum + val, 0) / recent.length;
  const olderAvg = older.reduce((sum, val) => sum + val, 0) / older.length;
  
  const change = ((recentAvg - olderAvg) / olderAvg) * 100;
  
  if (Math.abs(change) < 1) {
    return { direction: 'stable', change: 0 };
  }
  
  return {
    direction: change > 0 ? 'up' : 'down',
    change: Math.abs(change)
  };
};

/**
 * Format API error messages
 */
export const formatApiError = (error: any): string => {
  if (typeof error === 'string') return error;
  if (error?.response?.data?.message) return error.response.data.message;
  if (error?.message) return error.message;
  return 'An unexpected error occurred';
};

/**
 * Generate hash for caching keys
 */
export const generateCacheKey = (...args: any[]): string => {
  return btoa(JSON.stringify(args)).replace(/[^a-zA-Z0-9]/g, '');
};

/**
 * Debounce function
 */
export const debounce = <T extends (...args: any[]) => any>(
  func: T,
  delay: number
): (...args: Parameters<T>) => void => {
  let timeoutId: ReturnType<typeof setTimeout>;
  
  return (...args: Parameters<T>) => {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => func(...args), delay);
  };
};

/**
 * Throttle function
 */
export const throttle = <T extends (...args: any[]) => any>(
  func: T,
  delay: number
): (...args: Parameters<T>) => void => {
  let lastCall = 0;
  
  return (...args: Parameters<T>) => {
    const now = Date.now();
    if (now - lastCall >= delay) {
      lastCall = now;
      func(...args);
    }
  };
};

/**
 * Deep merge objects
 */
export const deepMerge = (target: any, source: any): any => {
  const output = { ...target };
  
  if (isObject(target) && isObject(source)) {
    Object.keys(source).forEach(key => {
      if (isObject(source[key])) {
        if (!(key in target)) {
          Object.assign(output, { [key]: source[key] });
        } else {
          output[key] = deepMerge(target[key], source[key]);
        }
      } else {
        Object.assign(output, { [key]: source[key] });
      }
    });
  }
  
  return output;
};

/**
 * Check if value is object
 */
export const isObject = (item: any): boolean => {
  return item && typeof item === 'object' && !Array.isArray(item);
};

/**
 * Convert RGB to HSL
 */
export const rgbToHsl = (r: number, g: number, b: number): [number, number, number] => {
  r /= 255;
  g /= 255;
  b /= 255;

  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  let h: number, s: number;
  const l = (max + min) / 2;

  if (max === min) {
    h = s = 0; // achromatic
  } else {
    const d = max - min;
    s = l > 0.5 ? d / (2 - max - min) : d / (max + min);

    switch (max) {
      case r: h = (g - b) / d + (g < b ? 6 : 0); break;
      case g: h = (b - r) / d + 2; break;
      case b: h = (r - g) / d + 4; break;
      default: h = 0;
    }

    h /= 6;
  }

  return [h * 360, s * 100, l * 100];
};

/**
 * Convert HSL to RGB
 */
export const hslToRgb = (h: number, s: number, l: number): [number, number, number] => {
  h /= 360;
  s /= 100;
  l /= 100;

  const hue2rgb = (p: number, q: number, t: number) => {
    if (t < 0) t += 1;
    if (t > 1) t -= 1;
    if (t < 1/6) return p + (q - p) * 6 * t;
    if (t < 1/2) return q;
    if (t < 2/3) return p + (q - p) * (2/3 - t) * 6;
    return p;
  };

  let r: number, g: number, b: number;

  if (s === 0) {
    r = g = b = l; // achromatic
  } else {
    const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
    const p = 2 * l - q;
    r = hue2rgb(p, q, h + 1/3);
    g = hue2rgb(p, q, h);
    b = hue2rgb(p, q, h - 1/3);
  }

  return [Math.round(r * 255), Math.round(g * 255), Math.round(b * 255)];
}; 