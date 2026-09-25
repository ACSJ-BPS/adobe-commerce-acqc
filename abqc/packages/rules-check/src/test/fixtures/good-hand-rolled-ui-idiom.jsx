import { Button, Dialog, Checkbox } from '@adobe/react-spectrum';

function MyButton({ onClick, children }) {
  return <Button variant="primary" onPress={onClick}>{children}</Button>;
}

function Modal({ children }) {
  return <Dialog>{children}</Dialog>;
}

function CustomToggle({ pressed, onChange }) {
  return <Checkbox isSelected={pressed} onChange={onChange}>Toggle</Checkbox>;
}
