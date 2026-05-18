import { Box, CircularProgress, Tooltip, Typography } from '@mui/material';

const GRADE_COLORS: Record<string, string> = {
  A: '#4caf50',
  B: '#2196f3',
  C: '#ff9800',
  D: '#ff5722',
  F: '#f44336',
};

const GRADE_DESCRIPTIONS: Record<string, string> = {
  A: 'A — Straightforward, < 1 sprint overhead',
  B: 'B — Moderate, 2–3 sprint overhead',
  C: 'C — Significant effort, dedicated upgrade project required',
  D: 'D — High risk, phased approach strongly recommended',
  F: 'F — Major architectural rework required before upgrade',
};

const SIZE_MAP = { sm: 80, md: 120, lg: 160 } as const;

interface Props {
  score: number;
  grade: 'A' | 'B' | 'C' | 'D' | 'F';
  size?: 'sm' | 'md' | 'lg';
}

export default function UpgradeRiskGauge({ score, grade, size = 'md' }: Props) {
  const px = SIZE_MAP[size];
  const color = GRADE_COLORS[grade] ?? '#9e9e9e';
  const description = GRADE_DESCRIPTIONS[grade] ?? '';
  const thickness = size === 'lg' ? 4 : 3;

  return (
    <Tooltip title={description} placement="top">
      <Box
        sx={{
          position: 'relative',
          display: 'inline-flex',
          width: px,
          height: px,
          cursor: 'default',
        }}
      >
        {/* Background track */}
        <CircularProgress
          variant="determinate"
          value={100}
          size={px}
          thickness={thickness}
          sx={{ color: 'action.hover', position: 'absolute' }}
        />
        {/* Score arc */}
        <CircularProgress
          variant="determinate"
          value={score}
          size={px}
          thickness={thickness}
          sx={{ color, position: 'absolute' }}
        />
        <Box
          sx={{
            top: 0, left: 0, bottom: 0, right: 0,
            position: 'absolute',
            display: 'flex',
            flexDirection: 'column',
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Typography
            variant={size === 'lg' ? 'h5' : size === 'md' ? 'h6' : 'body1'}
            fontWeight={700}
            sx={{ color, lineHeight: 1 }}
          >
            {Math.round(score)}
          </Typography>
          <Typography
            variant={size === 'lg' ? 'h6' : 'caption'}
            fontWeight={700}
            sx={{ color }}
          >
            {grade}
          </Typography>
        </Box>
      </Box>
    </Tooltip>
  );
}
