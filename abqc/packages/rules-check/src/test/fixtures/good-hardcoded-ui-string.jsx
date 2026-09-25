import { useMessageFormatter } from '@adobe/react-spectrum';
import messages from '../messages/en-US.json';

function SubmitButton() {
  const format = useMessageFormatter(messages);
  return <Button>{format('submit_order')}</Button>;
}

function CloseButton() {
  const format = useMessageFormatter(messages);
  return (
    <ActionButton aria-label={format('close_dialog')}>
      <Icon />
    </ActionButton>
  );
}
