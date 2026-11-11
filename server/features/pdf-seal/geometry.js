export function mapViewportRectToPdf(viewport, rectangle) {
  const points = [
    [rectangle.x, rectangle.y],
    [rectangle.x + rectangle.width, rectangle.y],
    [rectangle.x, rectangle.y + rectangle.height],
    [rectangle.x + rectangle.width, rectangle.y + rectangle.height],
  ].map(([x, y]) => viewport.convertToPdfPoint(x, y))
  const xValues = points.map(([x]) => x)
  const yValues = points.map(([, y]) => y)
  const minX = Math.min(...xValues)
  const minY = Math.min(...yValues)

  return {
    x: minX,
    y: minY,
    width: Math.max(...xValues) - minX,
    height: Math.max(...yValues) - minY,
  }
}

/**
 * Vị trí vẽ ảnh (pdf-lib drawImage) để ảnh hiển thị đứng thẳng trong khung màn hình
 * `rectangle` (toạ độ viewport), kể cả khi trang PDF có /Rotate.
 * Neo là góc dưới-trái của ảnh; `rotate` là góc (độ, ngược chiều kim đồng hồ).
 */
export function mapViewportImageToPdf(viewport, rectangle) {
  const [blX, blY] = viewport.convertToPdfPoint(rectangle.x, rectangle.y + rectangle.height)
  const [brX, brY] = viewport.convertToPdfPoint(rectangle.x + rectangle.width, rectangle.y + rectangle.height)
  const [tlX, tlY] = viewport.convertToPdfPoint(rectangle.x, rectangle.y)

  return {
    x: blX,
    y: blY,
    width: Math.hypot(brX - blX, brY - blY),
    height: Math.hypot(tlX - blX, tlY - blY),
    rotate: Math.round((Math.atan2(brY - blY, brX - blX) * 180) / Math.PI),
  }
}

/** Khung ảnh tỉ lệ `aspect` (rộng/cao) nằm giữa khung `rectangle`, không méo ảnh. */
export function fitRectangle(rectangle, aspect) {
  if (!(aspect > 0)) return rectangle
  const width = Math.min(rectangle.width, rectangle.height * aspect)
  const height = width / aspect
  return {
    x: rectangle.x + (rectangle.width - width) / 2,
    y: rectangle.y + (rectangle.height - height) / 2,
    width,
    height,
  }
}
