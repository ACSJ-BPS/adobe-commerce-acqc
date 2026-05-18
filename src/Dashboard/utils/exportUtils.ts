/**
 * Export utilities for M2-Quality Dashboard
 * Provides functionality to export data in various formats
 */

export interface ExportOptions {
  format: 'json' | 'csv' | 'excel' | 'pdf';
  filename?: string;
  includeCharts?: boolean;
  includeMetadata?: boolean;
}

export interface ExportData {
  qualityMetrics?: any;
  violations?: any;
  trends?: any;
  teamPerformance?: any;
  riskAssessment?: any;
  metadata?: {
    projectId?: string;
    timeRange?: string;
    exportDate: string;
    version: string;
  };
}

/**
 * Export data as JSON
 */
export const exportAsJSON = (data: ExportData, options: ExportOptions): void => {
  const exportData = {
    ...data,
    metadata: {
      ...data.metadata,
      exportDate: new Date().toISOString(),
      version: '1.0.0',
      format: 'json'
    }
  };

  const blob = new Blob([JSON.stringify(exportData, null, 2)], {
    type: 'application/json'
  });

  downloadBlob(blob, `${options.filename || 'm2quality-data'}.json`);
};

/**
 * Export data as CSV
 */
export const exportAsCSV = (data: ExportData, options: ExportOptions): void => {
  const csvContent = generateCSVContent(data);
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  downloadBlob(blob, `${options.filename || 'm2quality-data'}.csv`);
};

/**
 * Export data as Excel (XLSX)
 */
export const exportAsExcel = async (data: ExportData, options: ExportOptions): Promise<void> => {
  try {
    // Dynamic import to avoid bundling XLSX in main bundle
    const XLSX = await import('xlsx');
    
    const workbook = XLSX.utils.book_new();
    
    // Add quality metrics sheet
    if (data.qualityMetrics) {
      const qualitySheet = XLSX.utils.json_to_sheet(data.qualityMetrics);
      XLSX.utils.book_append_sheet(workbook, qualitySheet, 'Quality Metrics');
    }
    
    // Add violations sheet
    if (data.violations) {
      const violationsSheet = XLSX.utils.json_to_sheet(data.violations);
      XLSX.utils.book_append_sheet(workbook, violationsSheet, 'Violations');
    }
    
    // Add trends sheet
    if (data.trends) {
      const trendsSheet = XLSX.utils.json_to_sheet(data.trends);
      XLSX.utils.book_append_sheet(workbook, trendsSheet, 'Trends');
    }
    
    // Add metadata sheet
    if (options.includeMetadata) {
      const metadataSheet = XLSX.utils.json_to_sheet([data.metadata || {}]);
      XLSX.utils.book_append_sheet(workbook, metadataSheet, 'Metadata');
    }
    
    const excelBuffer = XLSX.write(workbook, { bookType: 'xlsx', type: 'array' });
    const blob = new Blob([excelBuffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    downloadBlob(blob, `${options.filename || 'm2quality-data'}.xlsx`);
  } catch (error) {
    console.error('Failed to export as Excel:', error);
    throw new Error('Excel export failed. Please try CSV or JSON format.');
  }
};

/**
 * Export data as PDF
 */
export const exportAsPDF = async (data: ExportData, options: ExportOptions): Promise<void> => {
  try {
    // Dynamic import to avoid bundling jsPDF in main bundle
    const { jsPDF } = await import('jspdf');
    const doc = new jsPDF();
    
    let yPosition = 20;
    const pageHeight = doc.internal.pageSize.height;
    const margin = 20;
    const lineHeight = 7;
    
    // Add title
    doc.setFontSize(18);
    doc.text('M2-Quality Dashboard Report', margin, yPosition);
    yPosition += lineHeight * 2;
    
    // Add metadata
    if (options.includeMetadata && data.metadata) {
      doc.setFontSize(12);
      doc.text(`Project: ${data.metadata.projectId || 'N/A'}`, margin, yPosition);
      yPosition += lineHeight;
      doc.text(`Time Range: ${data.metadata.timeRange || 'N/A'}`, margin, yPosition);
      yPosition += lineHeight;
      doc.text(`Export Date: ${new Date().toLocaleDateString()}`, margin, yPosition);
      yPosition += lineHeight * 2;
    }
    
    // Add quality metrics
    if (data.qualityMetrics) {
      doc.setFontSize(14);
      doc.text('Quality Metrics', margin, yPosition);
      yPosition += lineHeight;
      
      doc.setFontSize(10);
      Object.entries(data.qualityMetrics).forEach(([key, value]) => {
        if (yPosition > pageHeight - margin) {
          doc.addPage();
          yPosition = margin;
        }
        doc.text(`${key}: ${value}`, margin + 10, yPosition);
        yPosition += lineHeight;
      });
      yPosition += lineHeight;
    }
    
    // Add violations summary
    if (data.violations) {
      if (yPosition > pageHeight - margin - 20) {
        doc.addPage();
        yPosition = margin;
      }
      
      doc.setFontSize(14);
      doc.text('Violations Summary', margin, yPosition);
      yPosition += lineHeight;
      
      doc.setFontSize(10);
      const violations = Array.isArray(data.violations) ? data.violations : [data.violations];
      violations.slice(0, 10).forEach((violation: any) => {
        if (yPosition > pageHeight - margin) {
          doc.addPage();
          yPosition = margin;
        }
        doc.text(`${violation.severity}: ${violation.message}`, margin + 10, yPosition);
        yPosition += lineHeight;
      });
    }
    
    const blob = doc.output('blob');
    downloadBlob(blob, `${options.filename || 'm2quality-report'}.pdf`);
  } catch (error) {
    console.error('Failed to export as PDF:', error);
    throw new Error('PDF export failed. Please try another format.');
  }
};

/**
 * Main export function
 */
export const exportDashboardData = async (data: ExportData, options: ExportOptions): Promise<void> => {
  try {
    switch (options.format) {
      case 'json':
        exportAsJSON(data, options);
        break;
      case 'csv':
        exportAsCSV(data, options);
        break;
      case 'excel':
        await exportAsExcel(data, options);
        break;
      case 'pdf':
        await exportAsPDF(data, options);
        break;
      default:
        throw new Error(`Unsupported export format: ${options.format}`);
    }
  } catch (error) {
    console.error('Export failed:', error);
    throw error;
  }
};

/**
 * Generate CSV content from data
 */
const generateCSVContent = (data: ExportData): string => {
  const lines: string[] = [];
  
  // Add metadata
  if (data.metadata) {
    lines.push('Metadata');
    lines.push('Project ID,Time Range,Export Date');
    lines.push(`${data.metadata.projectId || ''},${data.metadata.timeRange || ''},${new Date().toISOString()}`);
    lines.push('');
  }
  
  // Add quality metrics
  if (data.qualityMetrics) {
    lines.push('Quality Metrics');
    lines.push('Metric,Value');
    Object.entries(data.qualityMetrics).forEach(([key, value]) => {
      lines.push(`${key},${value}`);
    });
    lines.push('');
  }
  
  // Add violations
  if (data.violations) {
    lines.push('Violations');
    lines.push('Severity,Category,Message,File,Line');
    const violations = Array.isArray(data.violations) ? data.violations : [data.violations];
    violations.forEach((violation: any) => {
      lines.push(`${violation.severity || ''},${violation.category || ''},${violation.message || ''},${violation.file || ''},${violation.line || ''}`);
    });
    lines.push('');
  }
  
  return lines.join('\n');
};

/**
 * Download blob as file
 */
const downloadBlob = (blob: Blob, filename: string): void => {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
};

/**
 * Export chart as image
 */
export const exportChartAsImage = (chartRef: React.RefObject<HTMLElement>, filename: string): void => {
  if (!chartRef.current) return;
  
  try {
    // Dynamic import to avoid bundling html2canvas in main bundle
    import('html2canvas').then(({ default: html2canvas }) => {
      html2canvas(chartRef.current!).then((canvas) => {
        canvas.toBlob((blob) => {
          if (blob) {
            downloadBlob(blob, `${filename}.png`);
          }
        });
      });
    });
  } catch (error) {
    console.error('Failed to export chart as image:', error);
  }
};

/**
 * Export dashboard screenshot
 */
export const exportDashboardScreenshot = async (): Promise<void> => {
  try {
    const { default: html2canvas } = await import('html2canvas');
    const dashboardElement = document.querySelector('[data-testid="dashboard"]') || document.body;
    
    const canvas = await html2canvas(dashboardElement as HTMLElement, {
      scale: 2,
      useCORS: true,
      allowTaint: true
    });
    
    canvas.toBlob((blob) => {
      if (blob) {
        downloadBlob(blob, `m2quality-dashboard-${new Date().toISOString().split('T')[0]}.png`);
      }
    });
  } catch (error) {
    console.error('Failed to export dashboard screenshot:', error);
  }
}; 