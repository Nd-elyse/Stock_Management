export const JOB_WORKFLOW_STATUSES = ['Pending', 'Diagnosed', 'InProgress', 'AwaitingParts', 'Ready', 'Delivered'];
export const JOB_ALL_STATUSES = [...JOB_WORKFLOW_STATUSES, 'Cancelled'];
export const JOB_STATUS_LABELS = { InProgress: 'In Progress', AwaitingParts: 'Awaiting Parts' };

export const normalizeJobStatus = (status) => ({ 'In Progress': 'InProgress', 'Awaiting Parts': 'AwaitingParts', Completed: 'Ready' }[status] || status || 'Pending');
export const jobStatusLabel = (status) => JOB_STATUS_LABELS[normalizeJobStatus(status)] || normalizeJobStatus(status);
export const isActiveJobStatus = (status) => JOB_WORKFLOW_STATUSES.slice(0, -2).includes(normalizeJobStatus(status));