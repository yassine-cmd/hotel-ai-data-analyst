import Modal from './Modal';
import Button from './Button';

export default function ConfirmDialog({ open, onClose, onConfirm, title = 'Êtes-vous sûr ?', message, confirmLabel = 'Confirmer', danger = false, confirmDisabled = false, children }) {
  return (
    <Modal open={open} onClose={onClose} title={title} size="sm" footer={
      <>
        <Button variant="ghost" size="sm" onClick={onClose}>Annuler</Button>
        <Button variant={danger ? 'danger' : 'primary'} size="sm" disabled={confirmDisabled} onClick={() => { onConfirm?.(); onClose?.(); }}>
          {confirmLabel}
        </Button>
      </>
    }>
      <p className="text-[13px] leading-relaxed text-fg-2">{message}</p>
      {children}
    </Modal>
  );
}
