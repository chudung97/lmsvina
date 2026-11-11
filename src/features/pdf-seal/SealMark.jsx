/**
 * Một con dấu đặt trên trang PDF (toạ độ theo viewport scale 1.3, hiển thị theo %).
 * mode: 'active' (dấu của tôi, đang sửa được) | 'signed' (đã khóa / của người khác).
 */
function SealMark({ seal, position = seal, pageSize, mode = 'signed', selected = false, onMoveStart, onResizeStart, onDelete, title }) {
  const interactive = mode === 'active'

  return (
    <div
      className={`seal ${seal.imageUrl ? 'seal-image' : 'seal-legacy'} seal-${mode}${selected ? ' seal-selected' : ''}`}
      title={title}
      style={{
        left: `${(position.x / pageSize.width) * 100}%`,
        top: `${(position.y / pageSize.height) * 100}%`,
        width: `${(position.width / pageSize.width) * 100}%`,
        height: `${(position.height / pageSize.height) * 100}%`,
        transform: position.rotation ? `rotate(${position.rotation}deg)` : undefined,
        background: seal.imageUrl ? 'transparent' : seal.color,
        pointerEvents: interactive ? 'auto' : 'none',
        cursor: interactive ? 'grab' : 'default',
      }}
      onPointerDown={interactive ? onMoveStart : undefined}
      onDoubleClick={(event) => event.stopPropagation()}
    >
      {seal.imageUrl ? (
        <img src={seal.imageUrl} alt={`Con dấu của ${seal.name}`} draggable={false} />
      ) : (
        <>
          <span>{seal.sealText}</span>
          <small>{seal.signatureName}</small>
        </>
      )}
      {interactive && (
        <>
          <button
            type="button"
            className="seal-delete"
            aria-label="Xóa con dấu này"
            onPointerDown={(event) => event.stopPropagation()}
            onClick={(event) => {
              event.stopPropagation()
              onDelete?.()
            }}
          >
            ×
          </button>
          <button
            type="button"
            className="seal-resize"
            aria-label="Kéo để đổi kích thước con dấu"
            onPointerDown={(event) => {
              event.stopPropagation()
              onResizeStart?.(event)
            }}
          />
        </>
      )}
    </div>
  )
}

export default SealMark
