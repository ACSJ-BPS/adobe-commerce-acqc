import { Box, Chip, Paper, Tooltip, Typography } from '@mui/material';

export interface SprintItem {
  sprint: number;
  name: string;
  total_hours: number;
  findings?: unknown[];
}

interface Props {
  sprints: SprintItem[];
  totalHours: number;
  recommendedTimeline?: string;
  goLiveRecommendation?: string;
}

const SPRINT_COLORS = [
  '#ef4444', // Sprint 1 — blockers (red)
  '#f97316', // Sprint 2 — critical errors (orange)
  '#eab308', // Sprint 3 — warnings (yellow)
  '#22c55e', // Sprint 4 — low priority (green)
  '#3b82f6', // Sprint 5+ — validation / QA (blue)
];

export default function UpgradeTimelineGantt({
  sprints,
  totalHours,
  recommendedTimeline,
  goLiveRecommendation,
}: Props) {
  if (sprints.length === 0) {
    return (
      <Typography variant="body2" color="text.secondary">
        No sprint plan available. Run a full upgrade analysis to generate the timeline.
      </Typography>
    );
  }

  const maxHours = Math.max(...sprints.map((s) => s.total_hours), 1);

  return (
    <Box>
      {(recommendedTimeline || goLiveRecommendation) && (
        <Box sx={{ mb: 2, display: 'flex', gap: 2, flexWrap: 'wrap' }}>
          {recommendedTimeline && (
            <Chip size="small" label={`Timeline: ${recommendedTimeline}`} color="info" />
          )}
          {goLiveRecommendation && (
            <Chip size="small" label={`Go-live: ${goLiveRecommendation}`} color="success" />
          )}
          <Chip size="small" label={`Total: ${totalHours}h (w/ contingency)`} variant="outlined" />
        </Box>
      )}

      <Paper variant="outlined" sx={{ p: 2 }}>
        {sprints.map((sprint, idx) => {
          const pct = (sprint.total_hours / maxHours) * 100;
          const color = SPRINT_COLORS[Math.min(idx, SPRINT_COLORS.length - 1)];
          const findingCount = sprint.findings?.length ?? 0;

          return (
            <Box key={sprint.sprint} sx={{ mb: 1.5 }}>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 0.5 }}>
                <Typography variant="caption" fontWeight={600}>
                  Sprint {sprint.sprint} — {sprint.name}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {sprint.total_hours}h · {findingCount} finding{findingCount !== 1 ? 's' : ''}
                </Typography>
              </Box>
              <Tooltip
                title={`Sprint ${sprint.sprint}: ${sprint.total_hours}h across ${findingCount} findings`}
              >
                <Box
                  sx={{
                    height: 20,
                    width: `${Math.max(pct, 2)}%`,
                    bgcolor: color,
                    borderRadius: 1,
                    transition: 'width 0.3s ease',
                  }}
                />
              </Tooltip>
            </Box>
          );
        })}
      </Paper>
    </Box>
  );
}
